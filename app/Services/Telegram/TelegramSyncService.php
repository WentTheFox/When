<?php

namespace App\Services\Telegram;

use App\Jobs\SyncTelegramContacts;
use App\Models\ConnectionAttributeValue;
use App\Models\TelegramFriend;
use App\Models\User;
use App\Services\Calendar\HighlightWordsResolver;
use App\Support\TelegramUserId;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * Pulls the owner's Telegram contacts and remembers them (§0.2 tier) so contacts without a connection can be
 * linked or turned into one. The browser does that part: it alone can read and encrypt connection names.
 * Telegram only lets a user account read contacts, so the owner logs in (phone, code, maybe a two-step
 * password) through the optional sidecar and the server keeps the resulting session encrypted.
 *
 * Same two ways in as Discord: the owner's "Sync now" (full: refreshes names, phone and photo of everyone
 * already tracked), and a background sync queued by request traffic (see maybeQueue) that only adds people not
 * tracked at all yet. Every contact is remembered, so deleting a connection never turns its contact "new" again,
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

    /** Most photos fetched per sync: the first sync of a big address book fills in over a few runs. */
    private const PHOTOS_PER_SYNC = 40;

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
                $fresh = $this->store($user, $fetched['contacts'], $onlyNew);

                if ($fresh !== []) {
                    // A photo that cannot be fetched is not worth failing the sync for: the contact just has no picture yet.
                    try {
                        $photos = $this->client->photos($session, array_slice($fresh, 0, self::PHOTOS_PER_SYNC));
                        $session = $photos['session'];
                        $this->storePhotos($user, $photos['photos']);
                    } catch (TelegramException $e) {
                        if ($e->authRevoked) {
                            throw $e;
                        }
                    }
                }
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
     * @return list<string> ids of the contacts that are new and have a photo to fetch
     */
    private function store(User $user, array $contacts, bool $onlyNew): array
    {
        $linked = array_flip($this->linkedIds($user));
        $known = TelegramFriend::where('user_id', $user->id)->get()->keyBy('telegram_user_id_hash');
        $needPhoto = [];

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
                    'status' => $isLinked ? TelegramFriend::CREATED : TelegramFriend::PENDING,
                    'synced_at' => now(),
                ]);

                if ($c['has_photo']) {
                    $needPhoto[] = $id;
                }

                continue;
            }

            if ($onlyNew) {
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
                'synced_at' => now(),
            ]);

            // Only a pending one can still change status (a connection made by hand in the meantime settles it).
            if ($row->status === TelegramFriend::PENDING && $isLinked) {
                $row->status = TelegramFriend::CREATED;
            }

            $row->save();

            if ($c['has_photo'] && $row->avatar_url === null) {
                $needPhoto[] = $id;
            }
        }

        return $needPhoto;
    }

    /** @param array<string, string> $photos id => data URL */
    private function storePhotos(User $user, array $photos): void
    {
        foreach ($photos as $id => $dataUrl) {
            TelegramFriend::where('user_id', $user->id)
                ->where('telegram_user_id_hash', TelegramUserId::hash((string) $id))
                ->first()
                ?->forceFill(['avatar_url' => $dataUrl])->save();
        }
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
     * @return array{available: bool, connected: bool, configured: bool, account_name: ?string, synced_at: ?string, pending_friends: int, field: array{id: string, type: string}|null, profiles: array<string, list<array{telegram_user_id: string, known: bool, username: ?string, name: ?string, picture_url: ?string}>>}
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
