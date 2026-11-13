<?php

namespace App\Services\Telegram;

use App\Jobs\SyncTelegramContacts;
use App\Models\ConnectionAttributeValue;
use App\Models\TelegramFriend;
use App\Models\User;
use App\Services\Calendar\HighlightWordsResolver;
use App\Support\TelegramUserId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Pulls the owner's Telegram contacts and remembers them (§0.2 tier) so contacts without a connection can be
 * linked or turned into one. The browser does that part: it alone can read and encrypt connection names.
 * Telegram only lets a user account read contacts, so the owner logs in (phone, code, maybe a two-step
 * password) through the optional sidecar and the server keeps the resulting session encrypted.
 *
 * Same two ways in as Discord: the owner's "Sync now" (full: refreshes names and phone of everyone already
 * tracked), and a background sync queued by request traffic (see maybeQueue) that only adds people not tracked
 * at all yet. Both fill in profile photos that are still missing, which is additive: an existing photo is
 * never replaced. Every contact is remembered, so deleting a connection never turns its contact "new" again,
 * and a sync never deletes anything.
 */
class TelegramSyncService
{
    /** An automatic sync is queued when the last one is older than this. */
    public const STALE_AFTER_MINUTES = 30;

    /** After an attempt (success or not), no automatic retry for this long. */
    private const AUTO_COOLDOWN_MINUTES = 10;

    /** How long a half-finished login (code sent, not yet entered) is kept. */
    private const LOGIN_TTL_MINUTES = 10;

    /** Photos asked for per call to the sidecar. */
    private const PHOTO_BATCH = 30;

    /** Most photo calls in one sync, and the point after which no new one is started: a big address book finishes over a few syncs. */
    private const PHOTO_MAX_BATCHES = 6;

    private const PHOTO_TIME_BUDGET_SECONDS = 50;

    /** A contact whose photo came back empty (hidden by their privacy settings) is not asked about again for this long. */
    private const PHOTO_RECHECK_DAYS = 14;

    public function __construct(private readonly TelegramClient $client) {}

    public function isConnected(User $user): bool
    {
        return $user->telegram_session !== null;
    }

    public function isConfigured(User $user): bool
    {
        return TelegramClient::isConfigured() && $this->isConnected($user) && $user->telegram_user_id_definition_id !== null;
    }

    /** Queues a background sync (new people only) if this owner's contact list is stale and nobody tried recently. */
    public function maybeQueue(User $user): void
    {
        if (! $this->isConfigured($user)) {
            return;
        }

        $stale = $user->telegram_synced_at === null || $user->telegram_synced_at->lt(now()->subMinutes(self::STALE_AFTER_MINUTES));

        if ($stale && Cache::add("telegram-auto-sync:{$user->id}", 1, now()->addMinutes(self::AUTO_COOLDOWN_MINUTES))) {
            SyncTelegramContacts::dispatch($user->id);
        }
    }

    /**
     * Step 1 of the login: Telegram sends a code to the owner's Telegram app. The half-finished login (a temporary
     * session, the phone and Telegram's code hash) is kept encrypted in the cache for a few minutes, never in the
     * database and never sent to the browser.
     *
     * @throws TelegramException
     */
    public function startLogin(User $user, string $phone): void
    {
        $this->assertAvailable();

        $started = $this->client->loginStart($phone);

        Cache::put($this->loginKey($user), Crypt::encryptString((string) json_encode([
            'session' => $started['session'], 'phone' => $phone, 'hash' => $started['phone_code_hash'],
        ])), now()->addMinutes(self::LOGIN_TTL_MINUTES));
    }

    /**
     * Step 2: the code Telegram sent.
     *
     * @return bool True when the account has two-step verification and the password is needed (step 3).
     *
     * @throws TelegramException
     */
    public function confirmCode(User $user, string $code): bool
    {
        $pending = $this->pendingLogin($user);

        $result = $this->client->loginCode($pending['session'], $pending['phone'], $pending['hash'], $code);

        if ($result['needs_password']) {
            // The session now has an authorization step left; keep it for the password.
            $this->keepLogin($user, ['session' => $result['session']] + $pending);

            return true;
        }

        $this->finishLogin($user, $result['session'], $result['account_name']);

        return false;
    }

    /**
     * Step 3, only for accounts with two-step verification.
     *
     * @throws TelegramException
     */
    public function confirmPassword(User $user, string $password): void
    {
        $pending = $this->pendingLogin($user);

        $result = $this->client->loginPassword($pending['session'], $password);

        $this->finishLogin($user, $result['session'], $result['account_name']);
    }

    /** Logs the session out at Telegram (best effort) and forgets it. Contacts already seen are kept. */
    public function disconnect(User $user): void
    {
        if ($user->telegram_session !== null && TelegramClient::isConfigured()) {
            $this->client->logout($user->telegram_session);
        }

        $this->forgetSession($user);
        Cache::forget($this->loginKey($user));
    }

    /**
     * @param  bool  $onlyNew  Add people not tracked yet and leave every existing row (names, phone, photo, status) as it is.
     * @return bool False when another sync of this owner was already running (nothing was done).
     *
     * @throws TelegramException
     */
    public function sync(User $user, bool $onlyNew = false): bool
    {
        if (! $this->isConnected($user)) {
            throw new TelegramException('Log in to Telegram in Settings first.');
        }

        if ($user->telegram_user_id_definition_id === null) {
            throw new TelegramException('Choose the Telegram user ID field in Settings first.');
        }

        $this->assertAvailable();

        $lock = Cache::lock("telegram-sync:{$user->id}", 300);

        if (! $lock->get()) {
            return false;
        }

        try {
            $session = (string) $user->telegram_session;

            try {
                $fetched = $this->client->contacts($session);
                $session = $fetched['session'];
                $this->store($user, $fetched['contacts'], $onlyNew);
                $session = $this->fetchPhotos($user, $session);
            } catch (TelegramException $e) {
                // The session is gone for good: forget it so the owner is asked to log in again, rather than retrying forever.
                if ($e->authRevoked) {
                    $this->forgetSession($user);
                }

                throw $e;
            }

            if (! $onlyNew) {
                $this->addNameTokens($user);
            }

            $user->forceFill([
                'telegram_session' => $session,
                'telegram_synced_at' => now(),
            ])->save();
        } finally {
            $lock->release();
        }

        return true;
    }

    /**
     * @param  array<string, array{username: ?string, first_name: ?string, last_name: ?string, phone: ?string, mutual: bool, premium: bool, has_photo: bool}>  $contacts
     */
    private function store(User $user, array $contacts, bool $onlyNew): void
    {
        $linked = array_flip($this->linkedIds($user));
        $known = TelegramFriend::where('user_id', $user->id)->get()->keyBy('telegram_user_id_hash');

        foreach ($contacts as $id => $c) {
            $id = (string) $id;

            if (TelegramUserId::normalize($id) === null) {
                continue;
            }

            $hash = TelegramUserId::hash($id);
            $row = $known->get($hash);
            $isLinked = isset($linked[$id]);

            if ($row === null) {
                TelegramFriend::create([
                    'user_id' => $user->id,
                    'telegram_user_id_hash' => $hash,
                    'telegram_user_id' => $id,
                    'username' => $c['username'],
                    'first_name' => $c['first_name'],
                    'last_name' => $c['last_name'],
                    'phone' => $c['phone'],
                    'is_mutual' => $c['mutual'],
                    'is_premium' => $c['premium'],
                    'has_photo' => $c['has_photo'],
                    'status' => $isLinked ? TelegramFriend::CREATED : TelegramFriend::PENDING,
                    'synced_at' => now(),
                ]);

                continue;
            }

            if ($onlyNew) {
                // Nothing the owner can see changes, but what Telegram says about the photo is bookkeeping for the
                // photo fetch below, and rows from before it was tracked have to catch up.
                if ($row->has_photo !== $c['has_photo']) {
                    $row->forceFill(['has_photo' => $c['has_photo']])->save();
                }

                continue;
            }

            // Names change. Missing data from Telegram (a contact hides their phone, say) keeps what we had.
            $row->fill([
                'username' => $c['username'] ?? $row->username,
                'first_name' => $c['first_name'] ?? $row->first_name,
                'last_name' => $c['last_name'],
                'phone' => $c['phone'] ?? $row->phone,
                'is_mutual' => $c['mutual'],
                'is_premium' => $c['premium'],
                'has_photo' => $c['has_photo'],
                'synced_at' => now(),
            ]);

            // Only a pending one can still change status (a connection made by hand in the meantime settles it).
            if ($row->status === TelegramFriend::PENDING && $isLinked) {
                $row->status = TelegramFriend::CREATED;
            }

            $row->save();
        }
    }

    /**
     * Contacts that have a photo Telegram reports and that we have no picture for, and have not asked about lately.
     *
     * @return Builder<TelegramFriend>
     */
    private function wantingPhotos(User $user): Builder
    {
        return TelegramFriend::where('user_id', $user->id)
            ->where('has_photo', true)
            ->whereNull('avatar_url')
            ->where(fn (Builder $q) => $q->whereNull('photo_checked_at')->orWhere('photo_checked_at', '<', now()->subDays(self::PHOTO_RECHECK_DAYS)));
    }

    /**
     * Fetches the photos of contacts that still lack one, a batch at a time, until none are left, the batch or time
     * budget runs out, or Telegram says to slow down. It is additive (it only ever fills an empty picture), so
     * the background sync does it too. A failure is logged and ends the photo step, not the sync: the contacts
     * are already stored and the rest is tried again next time. Returns the session as the sidecar last handed it back.
     *
     * @throws TelegramException when the session itself is gone
     */
    private function fetchPhotos(User $user, string $session): string
    {
        $started = microtime(true);

        for ($batch = 0; $batch < self::PHOTO_MAX_BATCHES && microtime(true) - $started < self::PHOTO_TIME_BUDGET_SECONDS; $batch++) {
            $rows = $this->wantingPhotos($user)->limit(self::PHOTO_BATCH)->get();

            if ($rows->isEmpty()) {
                break;
            }

            $ids = [];
            foreach ($rows as $row) {
                $ids[] = $row->telegram_user_id;
            }

            try {
                $result = $this->client->photos($session, $ids);
            } catch (TelegramException $e) {
                if ($e->authRevoked) {
                    throw $e;
                }

                Log::warning('Telegram photo fetch failed: '.$e->getMessage());

                break;
            }

            $session = $result['session'];
            $checked = array_flip($result['checked']);

            foreach ($rows as $row) {
                $id = $row->telegram_user_id;

                // Only what the sidecar actually asked Telegram about is marked: the rest was not reached and stays queued.
                if (isset($result['photos'][$id])) {
                    $row->forceFill(['avatar_url' => $result['photos'][$id], 'photo_checked_at' => now()])->save();
                } elseif (isset($checked[$id])) {
                    $row->forceFill(['photo_checked_at' => now()])->save();
                }
            }

            // Telegram asked us to slow down: keep what arrived, and leave the rest for a later sync.
            if ($result['retry_after'] !== null) {
                break;
            }

            // A whole batch came back with nothing checked: asking again would loop on the same rows.
            if ($result['checked'] === [] && $result['photos'] === []) {
                break;
            }
        }

        return $session;
    }

    /**
     * Every Telegram user id already on one of this owner's connections, in the chosen field.
     *
     * @return list<string>
     */
    public function linkedIds(User $user): array
    {
        return array_values(array_unique(array_merge([], ...array_values($this->idsByConnection($user)))));
    }

    /**
     * connection id => the Telegram user ids on it.
     *
     * @return array<string, list<string>>
     */
    private function idsByConnection(User $user): array
    {
        if ($user->telegram_user_id_definition_id === null) {
            return [];
        }

        $byConnection = [];

        ConnectionAttributeValue::where('attribute_definition_id', $user->telegram_user_id_definition_id)
            ->whereHas('connection', fn ($q) => $q->where('user_id', $user->id))
            ->get()
            ->each(function (ConnectionAttributeValue $row) use (&$byConnection) {
                foreach ([...($row->identifier_list_value ?? []), $row->identifier_value] as $value) {
                    $id = TelegramUserId::normalize(is_string($value) ? $value : null);

                    if ($id !== null && ! in_array($id, $byConnection[$row->connection_id] ?? [], true)) {
                        $byConnection[$row->connection_id][] = $id;
                    }
                }
            });

        return $byConnection;
    }

    /**
     * Keeps each linked contact's name and username on their connection as highlight tokens
     * (see HighlightWordsResolver::addToken), as Discord and VRChat names are: a rename adds the new name next
     * to the old one and nothing is ever removed. No-op without a highlight field.
     */
    public function addNameTokens(User $user): void
    {
        if ($user->highlight_identifier_definition_id === null) {
            return;
        }

        $byConnection = $this->idsByConnection($user);

        if ($byConnection === []) {
            return;
        }

        $friends = TelegramFriend::where('user_id', $user->id)->get()->keyBy('telegram_user_id_hash');

        foreach ($byConnection as $connectionId => $ids) {
            foreach ($ids as $id) {
                $friend = $friends->get(TelegramUserId::hash($id));

                foreach ([$friend?->displayName(), $friend?->username] as $name) {
                    if ($name !== null) {
                        HighlightWordsResolver::addToken($user, $connectionId, $name);
                    }
                }
            }
        }
    }

    /**
     * What the connection form shows per connection: every Telegram contact on it that the sync has seen.
     *
     * @return array<string, list<array{telegram_user_id: string, known: bool, username: ?string, name: ?string, picture_url: ?string}>>
     */
    private function profiles(User $user): array
    {
        $byConnection = $this->idsByConnection($user);

        if ($byConnection === []) {
            return [];
        }

        $friends = TelegramFriend::where('user_id', $user->id)->get()->keyBy('telegram_user_id_hash');
        $profiles = [];

        foreach ($byConnection as $connectionId => $ids) {
            foreach ($ids as $id) {
                $friend = $friends->get(TelegramUserId::hash($id));

                $profiles[$connectionId][] = [
                    'telegram_user_id' => $id,
                    'known' => $friend !== null,
                    'username' => $friend?->username,
                    'name' => $friend?->displayName(),
                    'picture_url' => $friend?->avatar_url,
                ];
            }
        }

        return $profiles;
    }

    /**
     * One connection's Telegram contacts, as profiles() shapes them.
     *
     * @return list<array{telegram_user_id: string, known: bool, username: ?string, name: ?string, picture_url: ?string}>
     */
    public function profilesFor(User $user, string $connectionId): array
    {
        return $this->profiles($user)[$connectionId] ?? [];
    }

    /**
     * What the Connections and Settings pages show.
     *
     * @return array{available: bool, connected: bool, configured: bool, account_name: ?string, synced_at: ?string, pending_friends: int, photos_pending: int, field: array{id: string, type: string}|null, profiles: array<string, list<array{telegram_user_id: string, known: bool, username: ?string, name: ?string, picture_url: ?string}>>}
     */
    public function summary(User $user): array
    {
        $field = $user->telegram_user_id_definition_id === null
            ? null
            : $user->connectionAttributeDefinitions()->where('id', $user->telegram_user_id_definition_id)->first(['id', 'type']);

        return [
            // False when the operator set no sidecar: the Settings card then stays hidden.
            'available' => TelegramClient::isConfigured(),
            'connected' => $this->isConnected($user),
            'configured' => $this->isConfigured($user),
            'account_name' => $user->telegram_account_name,
            'synced_at' => $user->telegram_synced_at instanceof Carbon ? $user->telegram_synced_at->toIso8601String() : null,
            'pending_friends' => $this->isConfigured($user)
                ? TelegramFriend::where('user_id', $user->id)->where('status', TelegramFriend::PENDING)->count()
                : 0,
            // Contacts whose photo is still to be fetched: a big address book fills in over a few syncs.
            'photos_pending' => $this->isConfigured($user) ? $this->wantingPhotos($user)->count() : 0,
            'field' => $field === null ? null : ['id' => $field->id, 'type' => $field->type],
            'profiles' => $this->profiles($user),
        ];
    }

    private function finishLogin(User $user, string $session, ?string $accountName): void
    {
        $user->forceFill([
            'telegram_session' => $session,
            'telegram_account_name' => $accountName,
            'telegram_synced_at' => null,
        ])->save();

        Cache::forget($this->loginKey($user));
    }

    private function forgetSession(User $user): void
    {
        $user->forceFill([
            'telegram_session' => null,
            'telegram_account_name' => null,
            'telegram_synced_at' => null,
        ])->save();
    }

    /** @throws TelegramException */
    private function assertAvailable(): void
    {
        if (! TelegramClient::isConfigured()) {
            throw new TelegramException('Telegram is not set up on this server.');
        }
    }

    /**
     * @return array{session: string, phone: string, hash: string}
     *
     * @throws TelegramException
     */
    private function pendingLogin(User $user): array
    {
        $this->assertAvailable();

        $stored = Cache::get($this->loginKey($user));
        $data = is_string($stored) ? json_decode(Crypt::decryptString($stored), true) : null;

        if (! is_array($data) || ! isset($data['session'], $data['phone'], $data['hash'])) {
            throw new TelegramException('That login expired. Start again to get a new code.');
        }

        return ['session' => (string) $data['session'], 'phone' => (string) $data['phone'], 'hash' => (string) $data['hash']];
    }

    /** @param array{session: string, phone: string, hash: string} $data */
    private function keepLogin(User $user, array $data): void
    {
        Cache::put($this->loginKey($user), Crypt::encryptString((string) json_encode($data)), now()->addMinutes(self::LOGIN_TTL_MINUTES));
    }

    private function loginKey(User $user): string
    {
        return "telegram-login:{$user->id}";
    }
}
