<?php

namespace App\Services\Discord;

use App\Models\ConnectionAttributeValue;
use App\Models\ConnectionVrchatProfile;
use App\Models\DiscordFriend;
use App\Models\User;
use App\Services\Calendar\HighlightWordsResolver;
use App\Support\DiscordUserId;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Pulls the owner's Discord friend list and remembers it (§0.2 tier) so friends
 * without a connection can be linked or turned into one. The browser does that
 * part: it alone can read and encrypt connection names.
 *
 * Runs only when the owner presses "Sync now": there is no automatic or scheduled sync,
 * so Discord is only ever called because someone asked. Every friend is remembered, so deleting a connection never turns its
 * friend "new" again, and a sync never deletes anything.
 */
class DiscordSyncService
{
    /** Refresh the access token when it expires within this many seconds. */
    private const REFRESH_MARGIN_SECONDS = 120;

    public function __construct(private readonly DiscordClient $client) {}

    public function isConnected(User $user): bool
    {
        return $user->discord_refresh_token !== null && $user->discord_access_token !== null;
    }

    public function isConfigured(User $user): bool
    {
        return DiscordClient::isConfigured() && $this->isConnected($user) && $user->discord_user_id_definition_id !== null;
    }

    /** Stores a fresh grant (after the OAuth callback) and who it belongs to. */
    public function connect(User $user, string $code): void
    {
        $tokens = $this->client->exchangeCode($code);
        $me = $this->client->me($tokens['access_token']);

        $this->storeTokens($user, $tokens);
        $user->forceFill(['discord_account_name' => $me['name'], 'discord_synced_at' => null])->save();
    }

    /** Forgets the grant (revoking it at Discord, best effort). Friends already seen are kept. */
    public function disconnect(User $user): void
    {
        if ($user->discord_refresh_token !== null) {
            $this->client->revoke($user->discord_refresh_token);
        }

        $this->forgetTokens($user);
    }

    /**
     * @return bool False when another sync of this owner was already running (nothing was done).
     *
     * @throws DiscordException
     */
    public function sync(User $user): bool
    {
        if (! $this->isConnected($user)) {
            throw new DiscordException('Connect your Discord account in Settings first.');
        }

        if ($user->discord_user_id_definition_id === null) {
            throw new DiscordException('Choose the Discord user ID field in Settings first.');
        }

        $lock = Cache::lock("discord-sync:{$user->id}", 300);

        if (! $lock->get()) {
            return false;
        }

        try {
            $friends = $this->fetch($user);
            $this->store($user, $friends);
            $this->addNameTokens($user);
            $user->forceFill(['discord_synced_at' => now()])->save();
        } finally {
            $lock->release();
        }

        return true;
    }

    /**
     * @return array<string, array{username: ?string, globalName: ?string, nickname: ?string, avatarUrl: ?string}>
     *
     * @throws DiscordException
     */
    private function fetch(User $user): array
    {
        try {
            $expires = $user->discord_token_expires_at;

            if ($expires === null || $expires->lt(now()->addSeconds(self::REFRESH_MARGIN_SECONDS))) {
                $this->storeTokens($user, $this->client->refresh((string) $user->discord_refresh_token));
            }

            return $this->client->friends((string) $user->discord_access_token);
        } catch (DiscordException $e) {
            // The grant is gone for good: forget it so the owner is asked to reconnect, rather than retrying forever.
            if ($e->authRevoked) {
                $this->forgetTokens($user);
            }

            throw $e;
        }
    }

    /**
     * @param  array<string, array{username: ?string, globalName: ?string, nickname: ?string, avatarUrl: ?string}>  $friends
     */
    private function store(User $user, array $friends): void
    {
        $linked = array_flip($this->linkedIds($user));
        $known = DiscordFriend::where('user_id', $user->id)->get()->keyBy('discord_user_id_hash');

        foreach ($friends as $id => $friend) {
            $hash = DiscordUserId::hash((string) $id);
            $row = $known->get($hash);
            $isLinked = isset($linked[$id]);

            if ($row === null) {
                DiscordFriend::create([
                    'user_id' => $user->id,
                    'discord_user_id_hash' => $hash,
                    'discord_user_id' => (string) $id,
                    'username' => $friend['username'],
                    'global_name' => $friend['globalName'],
                    'nickname' => $friend['nickname'],
                    'avatar_url' => $friend['avatarUrl'],
                    'status' => $isLinked ? DiscordFriend::CREATED : DiscordFriend::PENDING,
                ]);

                continue;
            }

            // Names change: keep them fresh on every row. Only a pending one can still change status
            // (a connection made by hand in the meantime settles it); a dismissed friend stays dismissed.
            $row->fill([
                'username' => $friend['username'] ?? $row->username,
                'global_name' => $friend['globalName'] ?? $row->global_name,
                'nickname' => $friend['nickname'],
                'avatar_url' => $friend['avatarUrl'] ?? $row->avatar_url,
            ]);

            if ($row->status === DiscordFriend::PENDING && $isLinked) {
                $row->status = DiscordFriend::CREATED;
            }

            // Looked up by id earlier and now a friend: they take part in the friend flow like any other.
            if ($row->status === DiscordFriend::LOOKUP) {
                $row->status = $isLinked ? DiscordFriend::CREATED : DiscordFriend::PENDING;
            }

            $row->save();
        }
    }

    /**
     * Every Discord user id already on one of this owner's connections: in the
     * chosen Discord field, or learned through VRCX.
     *
     * @return list<string>
     */
    public function linkedIds(User $user): array
    {
        return array_values(array_unique(array_merge([], ...array_values($this->idsByConnection($user)))));
    }

    /**
     * connection id => the Discord user ids on it (chosen field, plus what VRCX reported).
     *
     * @return array<string, list<string>>
     */
    private function idsByConnection(User $user): array
    {
        $byConnection = [];
        $add = function (string $connectionId, ?string $value) use (&$byConnection): void {
            $id = DiscordUserId::normalize($value);

            if ($id !== null && ! in_array($id, $byConnection[$connectionId] ?? [], true)) {
                $byConnection[$connectionId][] = $id;
            }
        };

        if ($user->discord_user_id_definition_id !== null) {
            ConnectionAttributeValue::where('attribute_definition_id', $user->discord_user_id_definition_id)
                ->whereHas('connection', fn ($q) => $q->where('user_id', $user->id))
                ->get()
                ->each(function (ConnectionAttributeValue $row) use ($add) {
                    foreach ([...($row->identifier_list_value ?? []), $row->identifier_value] as $value) {
                        $add($row->connection_id, is_string($value) ? $value : null);
                    }
                });
        }

        ConnectionVrchatProfile::whereIn('connection_id', $user->connections()->select('id'))
            ->whereNotNull('discord_id')
            ->get()
            ->each(fn (ConnectionVrchatProfile $profile) => $add($profile->connection_id, $profile->discord_id));

        return $byConnection;
    }

    /**
     * Keeps each linked friend's display name and username on their connection as highlight
     * tokens (see HighlightWordsResolver::addToken), as VRChat names are: a rename adds the new
     * name next to the old one and nothing is ever removed. Covers every friend on a connection,
     * whatever their pending/created/dismissed status. Your private nickname for them is not
     * copied: it is your own label, not a name they go by. No-op without a highlight field.
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

        $friends = DiscordFriend::where('user_id', $user->id)->get()->keyBy('discord_user_id_hash');

        foreach ($byConnection as $connectionId => $ids) {
            foreach ($ids as $id) {
                $friend = $friends->get(DiscordUserId::hash($id));

                foreach ([$friend?->global_name, $friend?->username] as $name) {
                    if ($name !== null) {
                        HighlightWordsResolver::addToken($user, $connectionId, $name);
                    }
                }
            }
        }
    }

    /**
     * What the connection form shows per connection: every Discord account on it. Names and avatar
     * come from the friend list or an earlier lookup; an id with neither is a placeholder.
     *
     * @return array<string, list<array{discord_user_id: string, known: bool, username: ?string, global_name: ?string, nickname: ?string, picture_url: ?string}>>
     */
    private function profiles(User $user): array
    {
        $byConnection = $this->idsByConnection($user);

        if ($byConnection === []) {
            return [];
        }

        $friends = DiscordFriend::where('user_id', $user->id)->get()->keyBy('discord_user_id_hash');
        $profiles = [];

        foreach ($byConnection as $connectionId => $ids) {
            foreach ($ids as $id) {
                $friend = $friends->get(DiscordUserId::hash($id));

                // An id nobody has looked up (and who isn't a synced friend) is still listed, as a placeholder
                // (known = false) the card can offer to look up.
                $profiles[$connectionId][] = [
                    'discord_user_id' => $id,
                    'known' => $friend !== null,
                    'username' => $friend?->username,
                    'global_name' => $friend?->global_name,
                    'nickname' => $friend?->nickname,
                    'picture_url' => $friend?->avatar_url,
                ];
            }
        }

        return $profiles;
    }

    /** @param array{access_token: string, refresh_token: string, expires_at: Carbon, scope: string} $tokens */
    private function storeTokens(User $user, array $tokens): void
    {
        $user->forceFill([
            'discord_access_token' => $tokens['access_token'],
            'discord_refresh_token' => $tokens['refresh_token'],
            'discord_token_expires_at' => $tokens['expires_at'],
        ])->save();
    }

    private function forgetTokens(User $user): void
    {
        $user->forceFill([
            'discord_access_token' => null,
            'discord_refresh_token' => null,
            'discord_token_expires_at' => null,
            'discord_account_name' => null,
            'discord_synced_at' => null,
        ])->save();
    }

    /**
     * Looks up the Discord accounts on one connection by id (the app's bot token: works for anyone,
     * friend or not), saves what Discord says so the connection form can show it, and adds the names as
     * highlight tokens. A lookup never makes someone a pending friend, and an existing friend's
     * nickname is left alone.
     *
     * @return list<array{discord_user_id: string, known: bool, username: ?string, global_name: ?string, nickname: ?string, picture_url: ?string}>
     *
     * @throws DiscordException
     */
    public function lookup(User $user, string $connectionId): array
    {
        if (! DiscordClient::canLookUp()) {
            throw new DiscordException('Looking up Discord users is not set up on this server.');
        }

        // A connection has a handful of ids at most; the cap keeps one click from becoming a burst of calls.
        $ids = array_slice($this->idsByConnection($user)[$connectionId] ?? [], 0, 10);

        if ($ids === []) {
            throw new DiscordException('This connection has no Discord user ID.');
        }

        $known = DiscordFriend::where('user_id', $user->id)->get()->keyBy('discord_user_id_hash');
        $found = 0;
        $error = null;

        foreach ($ids as $id) {
            try {
                $profile = $this->client->user($id);
            } catch (DiscordException $e) {
                $error ??= $e;

                continue;
            }

            $found++;
            $hash = DiscordUserId::hash($id);
            $row = $known->get($hash);

            if ($row === null) {
                DiscordFriend::create([
                    'user_id' => $user->id,
                    'discord_user_id_hash' => $hash,
                    'discord_user_id' => $id,
                    'username' => $profile['username'],
                    'global_name' => $profile['globalName'],
                    'avatar_url' => $profile['avatarUrl'],
                    'status' => DiscordFriend::LOOKUP,
                ]);
            } else {
                $row->update([
                    'username' => $profile['username'] ?? $row->username,
                    'global_name' => $profile['globalName'] ?? $row->global_name,
                    'avatar_url' => $profile['avatarUrl'] ?? $row->avatar_url,
                ]);
            }
        }

        // Nothing came back at all (so every lookup failed): tell the owner why, instead of silently showing the same placeholder.
        if ($found === 0) {
            throw $error;
        }

        $this->addNameTokens($user);

        return $this->profiles($user)[$connectionId] ?? [];
    }

    /**
     * What the Connections and Settings pages show.
     *
     * @return array{available: bool, connected: bool, configured: bool, account_name: ?string, synced_at: ?string, pending_friends: int, lookup_available: bool, field: array{id: string, type: string}|null, profiles: array<string, list<array{discord_user_id: string, known: bool, username: ?string, global_name: ?string, nickname: ?string, picture_url: ?string}>>}
     */
    public function summary(User $user): array
    {
        $field = $user->discord_user_id_definition_id === null
            ? null
            : $user->connectionAttributeDefinitions()->where('id', $user->discord_user_id_definition_id)->first(['id', 'type']);

        return [
            'available' => DiscordClient::isConfigured(),
            // The server has a bot token, so a connection's Discord card can look a user up by id.
            'lookup_available' => DiscordClient::canLookUp(),
            'connected' => $this->isConnected($user),
            'configured' => $this->isConfigured($user),
            'account_name' => $user->discord_account_name,
            'synced_at' => $user->discord_synced_at instanceof Carbon ? $user->discord_synced_at->toIso8601String() : null,
            'pending_friends' => $this->isConfigured($user)
                ? DiscordFriend::where('user_id', $user->id)->where('status', DiscordFriend::PENDING)->count()
                : 0,
            // The custom field holding Discord user ids, with the value shape its type takes.
            'field' => $field === null ? null : ['id' => $field->id, 'type' => $field->type],
            // Connection id to the synced friends it is linked to (the connection form shows their avatar and names).
            'profiles' => $this->profiles($user),
        ];
    }
}
