<?php

namespace App\Services\Vrcx;

use App\Jobs\SyncVrcxData;
use App\Models\Connection;
use App\Models\ConnectionAttributeValue;
use App\Models\ConnectionVrchatProfile;
use App\Models\ConnectionVrchatStay;
use App\Models\ShareLink;
use App\Models\User;
use App\Models\VrchatFriend;
use App\Services\Calendar\HighlightWordsResolver;
use App\Support\VrchatUserId;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Pulls name/picture and time-together data from the owner's VRCX server for
 * every VRChat user id on their connections (the identifier / identifier_list
 * field selected in Settings) and stores it, §0.2 tier.
 *
 * Triggered by request traffic (see maybeQueue) or the owner's "Sync now", not
 * a cron sweep. Stays are upserted, never deleted by a sync, so totals keep
 * growing past VRCX's 90-day window; only a link that is no longer on any
 * connection has its cached data dropped.
 */
class VrcxSyncService
{
    /** An automatic sync is queued when the last one is older than this. */
    public const STALE_AFTER_MINUTES = 15;

    /** After an attempt (success or not), no automatic retry for this long. */
    private const AUTO_COOLDOWN_MINUTES = 10;

    public function isConfigured(User $user): bool
    {
        return $user->vrchat_user_id_definition_id !== null && VrcxClient::forUser($user) !== null;
    }

    /** Queues a background sync if this owner's data is stale and nobody tried recently. */
    public function maybeQueue(User $user): void
    {
        if (! $this->isConfigured($user)) {
            return;
        }

        $stale = $user->vrcx_synced_at === null || $user->vrcx_synced_at->lt(now()->subMinutes(self::STALE_AFTER_MINUTES));

        if ($stale && Cache::add("vrcx-auto-sync:{$user->id}", 1, now()->addMinutes(self::AUTO_COOLDOWN_MINUTES))) {
            SyncVrcxData::dispatch($user->id);
        }
    }

    /**
     * @return bool False when another sync of this owner was already running (nothing was done).
     *
     * @throws VrcxException
     */
    public function sync(User $user): bool
    {
        $client = VrcxClient::forUser($user);
        $definitionId = $user->vrchat_user_id_definition_id;

        if ($client === null || $definitionId === null) {
            throw new VrcxException('Set the VRCX server, an API key and the VRChat user ID field in Settings first.');
        }

        $lock = Cache::lock("vrcx-sync:{$user->id}", 300);

        if (! $lock->get()) {
            return false;
        }

        try {
            $this->run($user, $client, $definitionId);
        } finally {
            $lock->release();
        }

        return true;
    }

    private function run(User $user, VrcxClient $client, string $definitionId): void
    {
        $links = $this->links($user, $definitionId);
        $ids = array_values(array_unique(array_merge([], ...array_values($links))));

        $profiles = $ids === [] ? [] : $client->users($ids);
        $stays = $ids === [] ? [] : $client->timeTogether($ids);

        foreach ($links as $connectionId => $connectionIds) {
            foreach ($connectionIds as $vrchatId) {
                $this->store($user, $connectionId, $vrchatId, $profiles[$vrchatId] ?? null, $stays[$vrchatId] ?? []);
            }
        }

        $this->prune($user, $links);

        if ($user->vrcx_auto_create_friends) {
            $this->syncFriends($user, $client, $links);
        }

        $user->forceFill(['vrcx_synced_at' => now()])->save();
    }

    /**
     * Records the friend list so friends without a connection can get one (the
     * browser links each to a matching connection or creates it: it alone can
     * read the names). Every friend is remembered, so deleting a connection never
     * turns its friend "new" again.
     *
     * @param  array<string, list<string>>  $links
     */
    private function syncFriends(User $user, VrcxClient $client, array $links): void
    {
        $friends = $client->friends();
        $linked = array_flip(array_merge([], ...array_values($links)));

        $holders = [];
        foreach ($links as $connectionId => $connectionIds) {
            foreach ($connectionIds as $linkedId) {
                $holders[$linkedId][] = $connectionId;
            }
        }

        $known = VrchatFriend::where('user_id', $user->id)->get()->keyBy('vrchat_user_id_hash');

        foreach ($friends as $id => $friend) {
            $hash = VrchatUserId::hash($id);
            $row = $known->get($hash);
            $name = $friend['displayName'] !== '' ? $friend['displayName'] : null;
            $picture = $friend['pictureUrl'] !== '' ? $friend['pictureUrl'] : null;

            // The friend list has their current name even when VRCX hasn't cached the user itself.
            if ($name !== null) {
                foreach ($holders[$id] ?? [] as $holderId) {
                    $this->addNameToken($user, $holderId, $name);
                }
            }

            if ($row === null) {
                VrchatFriend::create([
                    'user_id' => $user->id,
                    'vrchat_user_id_hash' => $hash,
                    'vrchat_user_id' => $id,
                    'display_name' => $name,
                    'profile_picture_url' => $picture,
                    'status' => isset($linked[$id]) ? VrchatFriend::CREATED : VrchatFriend::PENDING,
                ]);
            } elseif ($row->status === VrchatFriend::PENDING) {
                // A connection made by hand in the meantime settles it; otherwise keep the name fresh.
                $row->update(isset($linked[$id])
                    ? ['status' => VrchatFriend::CREATED]
                    : ['display_name' => $name ?? $row->display_name, 'profile_picture_url' => $picture ?? $row->profile_picture_url]);
            }
        }
    }

    /**
     * connection id => the VRChat user ids on it.
     *
     * @return array<string, list<string>>
     */
    private function links(User $user, string $definitionId): array
    {
        $links = [];

        $rows = ConnectionAttributeValue::where('attribute_definition_id', $definitionId)
            ->whereHas('connection', fn ($q) => $q->where('user_id', $user->id))
            ->get();

        foreach ($rows as $row) {
            $ids = $this->idsOf($row);

            if ($ids !== []) {
                $links[$row->connection_id] = $ids;
            }
        }

        return $links;
    }

    /**
     * The valid VRChat user ids in one value of the VRChat field.
     *
     * @return list<string>
     */
    private function idsOf(ConnectionAttributeValue $row): array
    {
        $candidates = $row->identifier_list_value ?? [];
        $candidates[] = $row->identifier_value;

        return array_values(array_unique(array_filter(array_map(
            fn ($value) => is_string($value) ? VrchatUserId::normalize($value) : null,
            $candidates,
        ))));
    }

    /**
     * Called when a connection is saved: fetches name, picture and time together for
     * the VRChat ids it now holds, as part of the save, so a freshly entered id shows
     * its profile straight away. Only does anything when the ids differ from what is
     * cached, and never fails the save: a VRCX that is down or slow is logged and the
     * next sync fills it in. Waits at most a few seconds.
     */
    public function syncConnection(User $user, Connection $connection): bool
    {
        $definitionId = $user->vrchat_user_id_definition_id;
        $client = VrcxClient::forUser($user, 8);

        if ($client === null || $definitionId === null) {
            return false;
        }

        $row = ConnectionAttributeValue::where('connection_id', $connection->id)->where('attribute_definition_id', $definitionId)->first();
        $ids = $row === null ? [] : $this->idsOf($row);

        $wanted = array_map(VrchatUserId::hash(...), $ids);
        $cached = ConnectionVrchatProfile::where('connection_id', $connection->id)->pluck('vrchat_user_id_hash')->all();

        if (array_diff($wanted, $cached) === [] && array_diff($cached, $wanted) === []) {
            return false;
        }

        try {
            $profiles = $ids === [] ? [] : $client->users($ids);
            $stays = $ids === [] ? [] : $client->timeTogether($ids);

            foreach ($ids as $vrchatId) {
                $this->store($user, $connection->id, $vrchatId, $profiles[$vrchatId] ?? null, $stays[$vrchatId] ?? []);
            }
        } catch (VrcxException $e) {
            Log::warning('VRCX fetch on save failed: '.$e->getMessage());

            return false;
        } finally {
            // Ids that are no longer on the connection lose their cached data, whatever happened above.
            $this->prune($user, $ids === [] ? [] : [$connection->id => $ids], onlyConnection: $connection->id);
        }

        return true;
    }

    /**
     * Keeps the VRChat display name on the connection as a highlight token. The
     * name is copied in, not referenced: when the person renames themselves the
     * new name is added next to the old one, so events titled with an earlier name
     * still highlight. Nothing here ever removes a token. No-op without a highlight
     * field selected in Settings.
     */
    private function addNameToken(User $user, string $connectionId, string $name): void
    {
        $definitionId = $user->highlight_identifier_definition_id;
        $name = trim($name);

        if ($definitionId === null || $name === '') {
            return;
        }

        $row = ConnectionAttributeValue::firstOrNew(['connection_id' => $connectionId, 'attribute_definition_id' => $definitionId]);
        $tokens = HighlightWordsResolver::normalize($row->identifier_list_value);

        foreach ($tokens as $token) {
            if (mb_strtolower($token) === mb_strtolower($name)) {
                return;
            }
        }

        $row->identifier_list_value = [...$tokens, $name];
        $row->save();

        // The computed share-link result embeds the highlight words.
        HighlightWordsResolver::invalidateCaches([ShareLink::where('connection_id', $connectionId)->value('id')]);
    }

    /**
     * @param  array{displayName: string, profilePictureUrl: string, avatarThumbnailUrl: string, discordId: ?string, note: ?string}|null  $profile  Null when VRCX has not cached this user: the previous values stay.
     * @param  list<array{start: string, end: string, duration_ms: int, other_player_count: int}>  $intervals
     */
    private function store(User $user, string $connectionId, string $vrchatId, ?array $profile, array $intervals): void
    {
        $hash = VrchatUserId::hash($vrchatId);

        $existing = ConnectionVrchatStay::where('connection_id', $connectionId)
            ->where('vrchat_user_id_hash', $hash)
            ->get()
            ->keyBy('dedupe_hash');

        foreach ($intervals as $interval) {
            $dedupe = VrchatUserId::hash($vrchatId.'|'.$interval['start']);
            $payload = ['vrchat_user_id' => $vrchatId] + $interval;
            $row = $existing->get($dedupe);

            if ($row === null) {
                ConnectionVrchatStay::create([
                    'connection_id' => $connectionId,
                    'vrchat_user_id_hash' => $hash,
                    'dedupe_hash' => $dedupe,
                    'payload' => $payload,
                ]);
            } elseif ($row->payload !== $payload) {
                // An interval that was still open at the last sync has a longer end now.
                $row->update(['payload' => $payload]);
            }
        }

        $record = ConnectionVrchatProfile::firstOrNew(['connection_id' => $connectionId, 'vrchat_user_id_hash' => $hash]);
        $record->vrchat_user_id = $vrchatId;

        if ($profile !== null) {
            $record->display_name = $profile['displayName'] !== '' ? $profile['displayName'] : $record->display_name;
            $record->profile_picture_url = $profile['profilePictureUrl'] !== '' ? $profile['profilePictureUrl'] : null;
            $record->avatar_thumbnail_url = $profile['avatarThumbnailUrl'] !== '' ? $profile['avatarThumbnailUrl'] : null;
            // Replaced by another id, never cleared: VRCX keeps the last one VRChat showed.
            $record->discord_id = $profile['discordId'] ?? $record->discord_id;

            // A note is only kept while it waits to be carried over. One already imported (same text) isn't stored again;
            // a changed one is, so it is offered once more. A missing note (no notes:read scope, or none) changes nothing.
            if ($profile['note'] !== null && self::noteHash($profile['note']) !== $record->note_imported_hash) {
                $record->vrchat_note = $profile['note'];
            }
            $record->profile_fetched_at = now();
        }

        $record->stats = $this->stats($connectionId, $hash);
        $record->save();

        if ($record->display_name !== null) {
            $this->addNameToken($user, $connectionId, $record->display_name);
        }
    }

    /**
     * @return array{stays: int, total_ms: int, one_on_one_ms: int, first_at: ?string, last_at: ?string}
     */
    private function stats(string $connectionId, string $hash): array
    {
        $stats = ['stays' => 0, 'total_ms' => 0, 'one_on_one_ms' => 0, 'first_at' => null, 'last_at' => null];

        ConnectionVrchatStay::where('connection_id', $connectionId)
            ->where('vrchat_user_id_hash', $hash)
            ->get()
            ->each(function (ConnectionVrchatStay $stay) use (&$stats) {
                $p = $stay->payload;
                $duration = (int) ($p['duration_ms'] ?? 0);
                $start = (string) ($p['start'] ?? '');
                $end = (string) ($p['end'] ?? '');

                $stats['stays']++;
                $stats['total_ms'] += $duration;
                $stats['one_on_one_ms'] += ((int) ($p['other_player_count'] ?? 1)) === 0 ? $duration : 0;
                $stats['first_at'] = $stats['first_at'] === null || $start < $stats['first_at'] ? $start : $stats['first_at'];
                $stats['last_at'] = $stats['last_at'] === null || $end > $stats['last_at'] ? $end : $stats['last_at'];
            });

        return $stats;
    }

    /**
     * Drops cached data for links that are gone: an id removed from a
     * connection, the field unselected or changed, a connection emptied.
     *
     * @param  array<string, list<string>>  $links
     * @param  string|null  $onlyConnection  Limit the sweep to one connection (the rest of $links is then not considered).
     */
    private function prune(User $user, array $links, ?string $onlyConnection = null): void
    {
        $keep = [];
        foreach ($links as $connectionId => $ids) {
            foreach ($ids as $id) {
                $keep[$connectionId.'|'.VrchatUserId::hash($id)] = true;
            }
        }

        $connectionIds = $onlyConnection !== null ? collect([$onlyConnection]) : $user->connections()->pluck('id');

        ConnectionVrchatProfile::whereIn('connection_id', $connectionIds)->get(['id', 'connection_id', 'vrchat_user_id_hash'])
            ->reject(fn ($p) => isset($keep[$p->connection_id.'|'.$p->vrchat_user_id_hash]))
            ->each(function ($p) {
                ConnectionVrchatStay::where('connection_id', $p->connection_id)->where('vrchat_user_id_hash', $p->vrchat_user_id_hash)->delete();
                $p->delete();
            });
    }

    /** Keyed hash of a VRChat note's text (never the text itself). */
    public static function noteHash(string $note): string
    {
        return VrchatUserId::hash('note:'.$note);
    }

    /**
     * The browser carried these notes over to the connections' encrypted notes: remember
     * which text that was and drop the plaintext. Each entry only counts if the note it
     * names is still exactly the one that was merged, so a note that changed in the
     * meantime stays pending.
     *
     * @param  list<array{connection_id: string, vrchat_user_id: string, note: string}>  $imported
     * @return int How many were settled.
     */
    public function markNotesImported(User $user, array $imported): int
    {
        $settled = 0;

        foreach ($imported as $item) {
            $profile = ConnectionVrchatProfile::whereIn('connection_id', $user->connections()->select('id'))
                ->where('connection_id', $item['connection_id'])
                ->where('vrchat_user_id_hash', VrchatUserId::hash(strtolower(trim($item['vrchat_user_id']))))
                ->first();

            if ($profile === null || $profile->vrchat_note === null || $profile->vrchat_note !== trim($item['note'])) {
                continue;
            }

            $profile->update(['note_imported_hash' => self::noteHash($profile->vrchat_note), 'vrchat_note' => null]);
            $settled++;
        }

        return $settled;
    }

    /**
     * One connection's linked accounts as the page shows them.
     *
     * @return list<array<string, mixed>>
     */
    public function profilesFor(string $connectionId): array
    {
        return array_values(ConnectionVrchatProfile::where('connection_id', $connectionId)->get()
            ->map(fn (ConnectionVrchatProfile $row) => $this->presentProfile($row))
            ->all());
    }

    /** @return array<string, mixed> */
    private function presentProfile(ConnectionVrchatProfile $row): array
    {
        $stats = $row->stats ?? [];

        return [
            'vrchat_user_id' => $row->vrchat_user_id,
            'display_name' => $row->display_name,
            'picture_url' => $row->profile_picture_url ?: $row->avatar_thumbnail_url,
            'discord_id' => $row->discord_id,
            // Present only while the note waits to be carried over to the connection's (encrypted) notes.
            'note' => $row->vrchat_note,
            'stays' => (int) ($stats['stays'] ?? 0),
            'total_ms' => (int) ($stats['total_ms'] ?? 0),
            'one_on_one_ms' => (int) ($stats['one_on_one_ms'] ?? 0),
            'first_at' => $stats['first_at'] ?? null,
            'last_at' => $stats['last_at'] ?? null,
        ];
    }

    /**
     * What the Connections page shows: per connection, each linked account's
     * profile and stats.
     *
     * @return array{configured: bool, synced_at: ?string, auto_create_friends: bool, pending_friends: int, field: array{id: string, type: string}|null, friends_source_id: ?string, highlight_field_id: ?string, discord_field_id: ?string, profiles: array<string, list<array<string, mixed>>>}
     */
    public function summary(User $user): array
    {
        $profiles = [];

        $rows = ConnectionVrchatProfile::whereIn('connection_id', $user->connections()->select('id'))->get();

        foreach ($rows as $row) {
            $profiles[$row->connection_id][] = $this->presentProfile($row);
        }

        $field = $user->vrchat_user_id_definition_id === null
            ? null
            : $user->connectionAttributeDefinitions()->where('id', $user->vrchat_user_id_definition_id)->first(['id', 'type']);

        return [
            'configured' => $this->isConfigured($user),
            'auto_create_friends' => $user->vrcx_auto_create_friends,
            // Friends waiting for the browser to create their connection (it alone can encrypt a name).
            'pending_friends' => $user->vrcx_auto_create_friends
                ? VrchatFriend::where('user_id', $user->id)->where('status', VrchatFriend::PENDING)->count()
                : 0,
            'friends_source_id' => $user->vrcx_friends_source_id,
            // The custom field the owner picked for Discord user IDs (the connection list marks connections that have one).
            'discord_field_id' => $user->discord_user_id_definition_id,
            // The identifier list holding each connection's highlight tokens: the browser also matches friends against it.
            'highlight_field_id' => $user->highlight_identifier_definition_id,
            'field' => $field === null ? null : ['id' => $field->id, 'type' => $field->type],
            'synced_at' => $user->vrcx_synced_at instanceof Carbon ? $user->vrcx_synced_at->toIso8601String() : null,
            'profiles' => $profiles,
        ];
    }
}
