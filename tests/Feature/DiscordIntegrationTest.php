<?php

namespace Tests\Feature;

use App\Jobs\SyncDiscordFriends;
use App\Models\Connection;
use App\Models\ConnectionAttributeDefinition;
use App\Models\ConnectionAttributeValue;
use App\Models\DiscordFriend;
use App\Models\User;
use App\Services\Account\AccountExportService;
use App\Services\Discord\DiscordSyncService;
use App\Support\DiscordUserId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipStream\ZipStream;

/** The Discord friends import: OAuth2 grant, token handling, friend sync, and the browser-side linking endpoints. */
class DiscordIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const OLD = '100000000000000001';

    private const LINKED = '100000000000000002';

    private const NEW = '100000000000000003';

    private const ACCESS = 'access-token-secret-xyz';

    private const REFRESH = 'refresh-token-secret-xyz';

    protected function setUp(): void
    {
        parent::setUp();

        // No test may reach the real Discord API: an unfaked request fails the test instead of going out.
        Http::preventStrayRequests();

        config([
            'services.discord.client_id' => 'client-id',
            'services.discord.client_secret' => 'client-secret-xyz',
            'services.discord.redirect' => 'https://when.test/settings/discord/callback',
        ]);
    }

    private function owner(bool $connected = true): User
    {
        $user = User::factory()->create();
        $definition = ConnectionAttributeDefinition::create([
            'id' => (string) Str::uuid(), 'user_id' => $user->id, 'label_ciphertext' => 'opaque', 'type' => 'identifier_list',
        ]);
        $user->forceFill(['discord_user_id_definition_id' => $definition->id])->save();

        if ($connected) {
            $user->forceFill([
                'discord_access_token' => self::ACCESS, 'discord_refresh_token' => self::REFRESH,
                'discord_token_expires_at' => now()->addDay(), 'discord_account_name' => 'Owner',
            ])->save();
        }

        return $user->refresh();
    }

    private function connectionWith(User $user, string $discordId): Connection
    {
        $connection = Connection::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'name_ciphertext' => 'opaque']);
        ConnectionAttributeValue::create([
            'connection_id' => $connection->id, 'attribute_definition_id' => $user->discord_user_id_definition_id, 'identifier_list_value' => [$discordId],
        ]);

        return $connection;
    }

    /** @param list<string> $ids */
    private function fakeFriends(array $ids, bool $withBlocked = false): void
    {
        $rows = array_map(fn ($id) => [
            'id' => '1', 'type' => 1, 'nickname' => $id === self::NEW ? 'Nick' : null, 'user_ignored' => false,
            'user' => ['id' => $id, 'username' => 'user'.substr($id, -2), 'global_name' => 'Friend '.substr($id, -2), 'avatar' => 'abc'],
        ], $ids);

        // Blocked users (2) are offered like friends, flagged as blocked; incoming/outgoing requests (3, 4) are never imported.
        if ($withBlocked) {
            $rows[] = ['id' => '9', 'type' => 2, 'user' => ['id' => '100000000000000099', 'username' => 'blocked', 'global_name' => null, 'avatar' => null]];
        }
        $rows[] = ['id' => '8', 'type' => 3, 'user' => ['id' => '100000000000000098', 'username' => 'incoming', 'global_name' => null, 'avatar' => null]];

        Http::swap($factory = new Factory);
        $factory->preventStrayRequests();
        $factory->fake(['discord.com/api/v10/users/@me/relationships' => Http::response($rows)]);
    }

    /** @return array<string, string> */
    private function statuses(User $user): array
    {
        return DiscordFriend::where('user_id', $user->id)->get()->mapWithKeys(fn ($f) => [$f->discord_user_id => $f->status])->sortKeys()->all();
    }

    public function test_the_first_sync_makes_friends_pending_unless_they_already_have_a_connection(): void
    {
        $user = $this->owner();
        $this->connectionWith($user, self::LINKED);
        $this->fakeFriends([self::OLD, self::LINKED]);

        $this->assertTrue(app(DiscordSyncService::class)->sync($user));

        $this->assertSame([self::OLD => 'pending', self::LINKED => 'created'], $this->statuses($user));
        $this->assertNotNull($user->refresh()->discord_synced_at);
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer '.self::ACCESS));
    }

    public function test_only_friends_and_blocked_users_are_imported_and_blocked_are_offered_flagged(): void
    {
        $user = $this->owner();
        $this->fakeFriends([self::OLD], withBlocked: true);

        app(DiscordSyncService::class)->sync($user);

        $this->assertSame([self::OLD => 'pending', '100000000000000099' => 'pending'], $this->statuses($user));

        $friends = $this->actingAs($user)->getJson(route('dashboard.discord.friends'))->assertOk()->json('friends');
        $this->assertEquals(['100000000000000099' => true, self::OLD => false], collect($friends)->pluck('blocked', 'discord_user_id')->sortKeys()->all());
    }

    public function test_a_blocked_user_with_a_connection_is_not_offered(): void
    {
        $user = $this->owner();
        $this->connectionWith($user, '100000000000000099');
        $this->fakeFriends([self::OLD], withBlocked: true);

        app(DiscordSyncService::class)->sync($user);

        $this->assertSame('created', $this->statuses($user)['100000000000000099']);
        $ids = collect($this->actingAs($user)->getJson('/dashboard/discord/friends')->json('friends'))->pluck('discord_user_id')->all();
        $this->assertSame([self::OLD], $ids);
    }

    public function test_a_dismissed_blocked_user_stays_dismissed_across_syncs(): void
    {
        $user = $this->owner();
        $this->fakeFriends([self::OLD], withBlocked: true);
        app(DiscordSyncService::class)->sync($user);
        DiscordFriend::where('user_id', $user->id)->where('discord_user_id_hash', DiscordUserId::hash('100000000000000099'))->update(['status' => 'dismissed']);

        $this->fakeFriends([self::OLD], withBlocked: true);
        app(DiscordSyncService::class)->sync($user);

        $this->assertSame('dismissed', $this->statuses($user)['100000000000000099']);
    }

    public function test_an_unblocked_user_drops_to_a_lookup_row_and_nothing_is_deleted(): void
    {
        $user = $this->owner();
        $this->fakeFriends([self::OLD], withBlocked: true);
        app(DiscordSyncService::class)->sync($user);

        Http::swap($factory = new Factory);
        $factory->fake(['discord.com/api/v10/users/@me/relationships' => Http::response([])]);
        app(DiscordSyncService::class)->sync($user);

        $this->assertSame('lookup', $this->statuses($user)['100000000000000099']);
        $this->assertFalse(DiscordFriend::where('user_id', $user->id)->get()->firstWhere('discord_user_id', '100000000000000099')->is_blocked);
    }

    public function test_a_discord_id_learned_through_vrcx_counts_as_linked(): void
    {
        $user = $this->owner();
        $connection = Connection::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'name_ciphertext' => 'opaque']);
        DB::table('connection_vrchat_profiles')->insert([
            'id' => (string) Str::uuid(), 'connection_id' => $connection->id,
            'vrchat_user_id_hash' => str_repeat('a', 64), 'vrchat_user_id' => Crypt::encryptString('usr_x'), 'display_name' => Crypt::encryptString('x'),
            'discord_id' => Crypt::encryptString(self::LINKED), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->fakeFriends([self::LINKED]);

        app(DiscordSyncService::class)->sync($user);

        $this->assertSame([self::LINKED => 'created'], $this->statuses($user));
    }

    public function test_a_friend_is_only_ever_new_once(): void
    {
        $user = $this->owner();
        $this->fakeFriends([self::OLD]);
        app(DiscordSyncService::class)->sync($user);

        DiscordFriend::where('user_id', $user->id)->update(['status' => DiscordFriend::DISMISSED]);
        app(DiscordSyncService::class)->sync($user);

        $this->assertSame([self::OLD => 'dismissed'], $this->statuses($user));
        $this->assertSame(1, DiscordFriend::count());
    }

    public function test_a_pending_friend_with_a_hand_made_connection_is_settled_and_names_stay_fresh(): void
    {
        $user = $this->owner();
        $this->fakeFriends([self::NEW]);
        app(DiscordSyncService::class)->sync($user);
        $this->assertSame('Nick', DiscordFriend::first()->nickname);

        $this->connectionWith($user, self::NEW);
        $this->fakeFriends([self::NEW]);
        app(DiscordSyncService::class)->sync($user);

        $this->assertSame([self::NEW => 'created'], $this->statuses($user));
    }

    public function test_friend_data_and_tokens_are_encrypted_at_rest(): void
    {
        $user = $this->owner();
        $this->fakeFriends([self::OLD]);
        app(DiscordSyncService::class)->sync($user);

        $friend = (array) DB::table('discord_friends')->first();
        foreach (['discord_user_id', 'username', 'global_name', 'avatar_url'] as $column) {
            $this->assertStringNotContainsString(self::OLD, (string) $friend[$column]);
            $this->assertStringNotContainsString('Friend', (string) $friend[$column]);
        }
        $this->assertSame(DiscordUserId::hash(self::OLD), $friend['discord_user_id_hash']);

        $row = (array) DB::table('users')->where('id', $user->id)->first();
        $this->assertStringNotContainsString(self::ACCESS, (string) $row['discord_access_token']);
        $this->assertStringNotContainsString(self::REFRESH, (string) $row['discord_refresh_token']);
        $this->assertArrayNotHasKey('discord_access_token', $user->toArray());
        $this->assertArrayNotHasKey('discord_refresh_token', $user->toArray());
    }

    public function test_an_expiring_token_is_refreshed_first_and_the_new_one_is_stored(): void
    {
        $user = $this->owner();
        $user->forceFill(['discord_token_expires_at' => now()->addSeconds(30)])->save();
        Http::fake([
            'discord.com/api/v10/oauth2/token' => Http::response(['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 604800, 'scope' => 'identify relationships.read']),
            'discord.com/api/v10/users/@me/relationships' => Http::response([]),
        ]);

        app(DiscordSyncService::class)->sync($user);

        $this->assertSame('new-access', $user->refresh()->discord_access_token);
        $this->assertSame('new-refresh', $user->discord_refresh_token);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/relationships') && $r->hasHeader('Authorization', 'Bearer new-access'));
    }

    public function test_a_revoked_grant_disconnects_and_asks_to_reconnect(): void
    {
        $user = $this->owner();
        Http::fake(['discord.com/api/v10/users/@me/relationships' => Http::response(['message' => '401: Unauthorized'], 401)]);

        $response = $this->actingAs($user)->postJson('/settings/discord/sync');

        $response->assertStatus(422);
        $this->assertStringContainsString('Connect it again', $response->json('message'));
        $user->refresh();
        $this->assertNull($user->discord_access_token);
        $this->assertNull($user->discord_refresh_token);
    }

    public function test_a_discord_outage_keeps_the_connection(): void
    {
        $user = $this->owner();
        Http::fake(['discord.com/api/v10/users/@me/relationships' => Http::response('boom', 503)]);

        $this->actingAs($user)->postJson('/settings/discord/sync')->assertStatus(422);

        $this->assertSame(self::ACCESS, $user->refresh()->discord_access_token);
    }

    public function test_errors_never_contain_tokens_or_the_client_secret(): void
    {
        $user = $this->owner();
        Http::fake(['discord.com/api/v10/users/@me/relationships' => Http::response('token '.self::ACCESS.' client-secret-xyz', 500)]);

        $body = $this->actingAs($user)->postJson('/settings/discord/sync')->getContent();

        foreach ([self::ACCESS, self::REFRESH, 'client-secret-xyz'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }

    public function test_sync_needs_a_connection_and_a_field(): void
    {
        $this->actingAs($this->owner(connected: false))->postJson('/settings/discord/sync')->assertStatus(422);

        $user = $this->owner();
        $user->forceFill(['discord_user_id_definition_id' => null])->save();
        $this->actingAs($user)->postJson('/settings/discord/sync')->assertStatus(422);
    }

    public function test_the_authorize_redirect_asks_for_the_right_scopes_and_remembers_state(): void
    {
        $response = $this->actingAs($this->owner(connected: false))->get('/settings/discord/connect');

        $response->assertRedirectContains('https://discord.com/oauth2/authorize?');
        $query = [];
        parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('client-id', $query['client_id']);
        $this->assertSame('identify relationships.read', $query['scope']);
        $this->assertSame('https://when.test/settings/discord/callback', $query['redirect_uri']);
        $this->assertSame(session('discord_oauth_state'), $query['state']);
        $this->assertArrayNotHasKey('client_secret', $query);
    }

    public function test_the_callback_exchanges_the_code_and_stores_the_grant(): void
    {
        $user = $this->owner(connected: false);
        Http::fake([
            'discord.com/api/v10/oauth2/token' => Http::response(['access_token' => self::ACCESS, 'refresh_token' => self::REFRESH, 'expires_in' => 604800, 'scope' => 'relationships.read identify']),
            'discord.com/api/v10/users/@me' => Http::response(['id' => '5', 'username' => 'owner', 'global_name' => 'The Owner']),
        ]);

        $this->actingAs($user)->withSession(['discord_oauth_state' => 'good'])
            ->get('/settings/discord/callback?code=abc&state=good')->assertRedirect(route('settings.edit'));

        $user->refresh();
        $this->assertSame(self::ACCESS, $user->discord_access_token);
        $this->assertSame('The Owner', $user->discord_account_name);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/oauth2/token') && $r['code'] === 'abc' && $r['grant_type'] === 'authorization_code');
    }

    public function test_the_callback_rejects_a_wrong_state_and_a_grant_without_the_friends_scope(): void
    {
        $user = $this->owner(connected: false);
        Http::fake();

        $this->actingAs($user)->withSession(['discord_oauth_state' => 'good'])
            ->get('/settings/discord/callback?code=abc&state=evil')->assertSessionHasErrors('discord');
        Http::assertNothingSent();

        Http::fake(['discord.com/api/v10/oauth2/token' => Http::response(['access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 1, 'scope' => 'identify'])]);
        $this->actingAs($user)->withSession(['discord_oauth_state' => 'good'])
            ->get('/settings/discord/callback?code=abc&state=good')->assertSessionHasErrors('discord');
        $this->assertNull($user->refresh()->discord_access_token);
    }

    public function test_disconnecting_revokes_and_forgets_the_grant_but_keeps_friends(): void
    {
        $user = $this->owner();
        $this->fakeFriends([self::OLD]);
        app(DiscordSyncService::class)->sync($user);
        Http::fake(['discord.com/api/v10/oauth2/token/revoke' => Http::response([])]);

        $this->actingAs($user)->delete('/settings/discord')->assertRedirect();

        $user->refresh();
        $this->assertNull($user->discord_access_token);
        $this->assertNull($user->discord_refresh_token);
        $this->assertSame(1, DiscordFriend::count());
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/token/revoke') && $r['token'] === self::REFRESH);
    }

    public function test_pending_friends_are_listed_sorted_and_complete_settles_only_the_owners_own(): void
    {
        $user = $this->owner();
        $this->fakeFriends([self::NEW, self::OLD]);
        app(DiscordSyncService::class)->sync($user);

        $list = $this->actingAs($user)->getJson('/dashboard/discord/friends')->assertOk()->json('friends');
        // Sorted case-insensitively by nickname, else name: "friend 01" (OLD) before "nick" (NEW).
        $this->assertSame([self::OLD, self::NEW], array_column($list, 'discord_user_id'));
        $this->assertSame('Nick', collect($list)->firstWhere('discord_user_id', self::NEW)['nickname']);

        $other = $this->owner();
        $stranger = DiscordFriend::create([
            'user_id' => $other->id, 'discord_user_id_hash' => DiscordUserId::hash(self::OLD), 'discord_user_id' => self::OLD, 'status' => 'pending',
        ]);
        $ids = collect($list)->pluck('id', 'discord_user_id');

        $this->actingAs($user)->postJson('/dashboard/discord/friends/complete', [
            'ids' => [$ids[self::NEW], $stranger->id], 'skipped' => [$ids[self::OLD]],
        ])->assertOk()->assertJson(['completed' => 1]);

        $this->assertEquals([self::NEW => 'created', self::OLD => 'dismissed'], $this->statuses($user));
        $this->assertSame('pending', $stranger->refresh()->status);
        $this->assertSame([], $this->actingAs($user)->getJson('/dashboard/discord/friends')->json('friends'));
    }

    public function test_a_background_sync_is_queued_when_stale_and_not_again_during_the_cooldown(): void
    {
        Queue::fake();
        Cache::flush();
        $user = $this->owner();

        $this->actingAs($user)->get('/dashboard/connections')->assertOk();
        $this->actingAs($user)->get('/dashboard/connections')->assertOk();

        Queue::assertPushed(SyncDiscordFriends::class, 1);
    }

    public function test_nothing_is_queued_when_fresh_or_not_connected(): void
    {
        Queue::fake();
        $fresh = $this->owner();
        $fresh->forceFill(['discord_synced_at' => now()->subMinutes(5)])->save();
        app(DiscordSyncService::class)->maybeQueue($fresh);
        app(DiscordSyncService::class)->maybeQueue($this->owner(connected: false));

        Queue::assertNothingPushed();
    }

    public function test_a_background_sync_adds_new_people_but_never_refreshes_existing_ones(): void
    {
        $user = $this->owner();
        $this->fakeFriend('100000000000000501', 'Old Name', 'oldname');
        app(DiscordSyncService::class)->sync($user);

        Http::swap($factory = new Factory);
        $factory->preventStrayRequests();
        $factory->fake(['discord.com/api/v10/users/@me/relationships' => Http::response([
            ['id' => '1', 'type' => 1, 'nickname' => null, 'user' => ['id' => '100000000000000501', 'username' => 'renamed', 'global_name' => 'New Name', 'avatar' => 'abc']],
            ['id' => '2', 'type' => 1, 'nickname' => null, 'user' => ['id' => '100000000000000502', 'username' => 'fresh', 'global_name' => 'Fresh', 'avatar' => null]],
            ['id' => '3', 'type' => 2, 'user' => ['id' => '100000000000000503', 'username' => 'nope', 'global_name' => null, 'avatar' => null]],
        ])]);

        (new SyncDiscordFriends($user->id))->handle(app(DiscordSyncService::class));

        $existing = DiscordFriend::where('user_id', $user->id)->where('discord_user_id_hash', DiscordUserId::hash('100000000000000501'))->first();
        $this->assertSame('oldname', $existing->username);
        $this->assertSame('Old Name', $existing->global_name);
        $this->assertNull($existing->avatar_url);
        $this->assertSame('Fresh', DiscordFriend::where('discord_user_id_hash', DiscordUserId::hash('100000000000000502'))->value('global_name'));
        $this->assertTrue((bool) DiscordFriend::where('discord_user_id_hash', DiscordUserId::hash('100000000000000503'))->value('is_blocked'));
    }

    public function test_the_manual_sync_is_rate_limited_so_a_click_cannot_become_a_burst(): void
    {
        $user = $this->owner();
        $this->fakeFriends([self::OLD]);

        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($user)->postJson('/settings/discord/sync')->assertOk();
        }

        $this->actingAs($user)->postJson('/settings/discord/sync')->assertStatus(429);
    }

    public function test_the_export_includes_friends_and_the_account_name_but_never_a_token(): void
    {
        $user = $this->owner();
        $this->fakeFriends([self::OLD]);
        app(DiscordSyncService::class)->sync($user);

        $files = [];
        $zip = \Mockery::mock(ZipStream::class);
        $zip->shouldReceive('addFile')->andReturnUsing(function (string $name, string $data) use (&$files) {
            $files[$name] = $data;
        });
        app(AccountExportService::class)->build($zip, $user->refresh());

        $friends = json_decode($files['connections/discord-friends.json'], true);
        $this->assertSame('server-decrypted', $friends['tier']);
        $this->assertSame(self::OLD, $friends['records'][0]['discord_user_id']);
        $this->assertSame('Owner', json_decode($files['account/profile.json'], true)['records'][0]['discord_account_name']);

        foreach ($files as $contents) {
            $this->assertStringNotContainsString(self::ACCESS, $contents);
            $this->assertStringNotContainsString(self::REFRESH, $contents);
        }
    }

    public function test_the_summary_gives_each_connection_its_linked_friends_and_a_placeholder_for_unknown_ids(): void
    {
        $user = $this->owner();
        $linked = $this->connectionWith($user, self::LINKED);
        $stranger = $this->connectionWith($user, '100000000000000077'); // not one of the friends, never looked up
        $this->fakeFriends([self::OLD, self::LINKED]);
        app(DiscordSyncService::class)->sync($user);

        $profiles = app(DiscordSyncService::class)->summary($user->refresh())['profiles'];

        $this->assertEqualsCanonicalizing([$linked->id, $stranger->id], array_keys($profiles));
        $this->assertSame([[
            'discord_user_id' => self::LINKED,
            'known' => true,
            'username' => 'user02',
            'global_name' => 'Friend 02',
            'nickname' => null,
            'picture_url' => 'https://cdn.discordapp.com/avatars/'.self::LINKED.'/abc.png?size=64',
        ]], $profiles[$linked->id]);
        $this->assertSame([[
            'discord_user_id' => '100000000000000077',
            'known' => false,
            'username' => null,
            'global_name' => null,
            'nickname' => null,
            'picture_url' => null,
        ]], $profiles[$stranger->id]);
    }

    public function test_a_discord_id_learned_through_vrcx_also_gets_its_profile(): void
    {
        $user = $this->owner();
        $connection = Connection::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'name_ciphertext' => 'opaque']);
        DB::table('connection_vrchat_profiles')->insert([
            'id' => (string) Str::uuid(), 'connection_id' => $connection->id,
            'vrchat_user_id_hash' => str_repeat('b', 64), 'vrchat_user_id' => Crypt::encryptString('usr_y'), 'display_name' => Crypt::encryptString('y'),
            'discord_id' => Crypt::encryptString(self::NEW), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->fakeFriends([self::NEW]);
        app(DiscordSyncService::class)->sync($user);

        $profile = app(DiscordSyncService::class)->summary($user->refresh())['profiles'][$connection->id][0];

        $this->assertSame(self::NEW, $profile['discord_user_id']);
        $this->assertSame('Nick', $profile['nickname']);
    }

    public function test_profiles_are_per_owner_and_a_dismissed_friend_still_shows_on_their_connection(): void
    {
        $user = $this->owner();
        $mine = $this->connectionWith($user, self::OLD);
        $this->fakeFriends([self::OLD]);
        app(DiscordSyncService::class)->sync($user);
        DiscordFriend::where('user_id', $user->id)->update(['status' => DiscordFriend::DISMISSED]);

        // Someone else's connection with the same id has no friend row of their own: a bare placeholder,
        // none of this owner's friend data.
        $other = $this->owner();
        $theirs = $this->connectionWith($other, self::OLD);

        $mineProfiles = app(DiscordSyncService::class)->summary($user->refresh())['profiles'];
        $this->assertSame([$mine->id], array_keys($mineProfiles));
        $this->assertTrue($mineProfiles[$mine->id][0]['known']);

        $theirProfiles = app(DiscordSyncService::class)->summary($other->refresh())['profiles'];
        $this->assertSame([$theirs->id], array_keys($theirProfiles));
        $this->assertFalse($theirProfiles[$theirs->id][0]['known']);
        $this->assertNull($theirProfiles[$theirs->id][0]['username']);
        $this->assertNull($theirProfiles[$theirs->id][0]['global_name']);
    }

    private function withHighlightField(User $user): ConnectionAttributeDefinition
    {
        $field = ConnectionAttributeDefinition::create([
            'id' => (string) Str::uuid(), 'user_id' => $user->id, 'label_ciphertext' => 'opaque', 'type' => 'identifier_list',
        ]);
        $user->forceFill(['highlight_identifier_definition_id' => $field->id])->save();

        return $field;
    }

    /** @return list<string> */
    private function highlightTokens(User $user, Connection $connection): array
    {
        return ConnectionAttributeValue::where('connection_id', $connection->id)
            ->where('attribute_definition_id', $user->refresh()->highlight_identifier_definition_id)
            ->first()?->identifier_list_value ?? [];
    }

    private function fakeFriend(string $id, ?string $globalName, ?string $username, ?string $nickname = null): void
    {
        // A fresh factory each time: stubs stack, and the first match would win over a later "renamed" one.
        Http::swap($factory = new Factory);
        $factory->preventStrayRequests();
        $factory->fake(['discord.com/api/v10/users/@me/relationships' => Http::response([[
            'id' => '1', 'type' => 1, 'nickname' => $nickname,
            'user' => ['id' => $id, 'username' => $username, 'global_name' => $globalName, 'avatar' => null],
        ]])]);
    }

    public function test_a_linked_friends_display_name_and_username_become_highlight_tokens(): void
    {
        $user = $this->owner();
        $this->withHighlightField($user);
        $connection = $this->connectionWith($user, self::LINKED);
        $other = $this->connectionWith($user, self::OLD);
        $this->fakeFriend(self::LINKED, 'Maple Sparrow', 'maplesparrow', 'Mabel');

        app(DiscordSyncService::class)->sync($user);

        // Their display name and username, but not your private nickname for them; nothing on connections that aren't theirs.
        $this->assertSame(['Maple Sparrow', 'maplesparrow'], $this->highlightTokens($user, $connection));
        $this->assertSame([], $this->highlightTokens($user, $other));
    }

    public function test_a_rename_adds_the_new_names_and_keeps_the_old_ones(): void
    {
        $user = $this->owner();
        $this->withHighlightField($user);
        $connection = $this->connectionWith($user, self::LINKED);
        ConnectionAttributeValue::create(['connection_id' => $connection->id, 'attribute_definition_id' => $user->highlight_identifier_definition_id, 'identifier_list_value' => ['Mom']]);

        $this->fakeFriend(self::LINKED, 'Maple Sparrow', 'maplesparrow');
        app(DiscordSyncService::class)->sync($user);
        $this->fakeFriend(self::LINKED, 'Maple Finch', 'maplesparrow');
        app(DiscordSyncService::class)->sync($user);
        app(DiscordSyncService::class)->sync($user);

        $this->assertSame(['Mom', 'Maple Sparrow', 'maplesparrow', 'Maple Finch'], $this->highlightTokens($user, $connection), 'copies are kept, nothing is removed or duplicated');
    }

    public function test_a_token_that_differs_only_in_case_is_not_added_again(): void
    {
        $user = $this->owner();
        $this->withHighlightField($user);
        $connection = $this->connectionWith($user, self::LINKED);
        ConnectionAttributeValue::create(['connection_id' => $connection->id, 'attribute_definition_id' => $user->highlight_identifier_definition_id, 'identifier_list_value' => ['MAPLE SPARROW']]);
        $this->fakeFriend(self::LINKED, 'maple sparrow', null);

        app(DiscordSyncService::class)->sync($user);

        $this->assertSame(['MAPLE SPARROW'], $this->highlightTokens($user, $connection));
    }

    public function test_no_tokens_are_added_without_a_highlight_field(): void
    {
        $user = $this->owner();
        $connection = $this->connectionWith($user, self::LINKED);
        $this->fakeFriend(self::LINKED, 'Maple Sparrow', 'maplesparrow');

        app(DiscordSyncService::class)->sync($user);

        $this->assertSame(1, ConnectionAttributeValue::where('connection_id', $connection->id)->count(), 'only the Discord id row exists');
    }

    public function test_linking_a_pending_friend_adds_the_tokens_at_once(): void
    {
        $user = $this->owner();
        $this->withHighlightField($user);
        $this->fakeFriend(self::NEW, 'Plum Finch', 'plumfinch');
        app(DiscordSyncService::class)->sync($user);
        $friend = DiscordFriend::where('user_id', $user->id)->firstOrFail();

        // The browser links the friend by putting their id on a connection, then reports it as done.
        $connection = $this->connectionWith($user, self::NEW);
        $this->actingAs($user)->postJson('/dashboard/discord/friends/complete', ['ids' => [$friend->id]])->assertOk();

        $this->assertSame(['Plum Finch', 'plumfinch'], $this->highlightTokens($user, $connection));
    }

    public function test_a_dismissed_friend_who_is_linked_still_gets_tokens(): void
    {
        $user = $this->owner();
        $this->withHighlightField($user);
        $connection = $this->connectionWith($user, self::LINKED);
        $this->fakeFriend(self::LINKED, 'Maple Sparrow', 'maplesparrow');
        app(DiscordSyncService::class)->sync($user);
        DiscordFriend::where('user_id', $user->id)->update(['status' => DiscordFriend::DISMISSED]);
        ConnectionAttributeValue::where('connection_id', $connection->id)->where('attribute_definition_id', $user->highlight_identifier_definition_id)->delete();

        app(DiscordSyncService::class)->sync($user);

        $this->assertSame(['Maple Sparrow', 'maplesparrow'], $this->highlightTokens($user, $connection));
    }

    private const BOT = 'bot-token-secret-xyz';

    private function fakeLookup(string $id, int $status = 200, ?array $body = null): void
    {
        Http::swap($factory = new Factory);
        $factory->preventStrayRequests();
        $factory->fake(['discord.com/api/v10/users/'.$id => Http::response($body ?? [
            'id' => $id, 'username' => 'plumfinch', 'global_name' => 'Plum Finch', 'avatar' => 'def',
        ], $status)]);
    }

    public function test_the_lookup_saves_a_non_friends_profile_without_making_them_a_pending_friend(): void
    {
        config(['services.discord.bot_token' => self::BOT]);
        $user = $this->owner(connected: false); // needs no connected Discord account
        $connection = $this->connectionWith($user, self::NEW);
        $this->fakeLookup(self::NEW);

        $response = $this->actingAs($user)->postJson("/dashboard/connections/{$connection->id}/discord/lookup")->assertOk();

        $this->assertSame([
            'discord_user_id' => self::NEW,
            'known' => true,
            'username' => 'plumfinch',
            'global_name' => 'Plum Finch',
            'nickname' => null,
            'picture_url' => 'https://cdn.discordapp.com/avatars/'.self::NEW.'/def.png?size=64',
        ], $response->json('profiles.0'));
        $this->assertSame([self::NEW => 'lookup'], $this->statuses($user));
        $this->assertSame([], $this->actingAs($this->owner())->getJson('/dashboard/discord/friends')->json('friends'));
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bot '.self::BOT));
        Http::assertNotSent(fn (Request $r) => str_contains($r->header('Authorization')[0] ?? '', self::ACCESS));
    }

    public function test_saving_a_connection_with_a_discord_id_looks_it_up_in_the_same_response(): void
    {
        config(['services.discord.bot_token' => self::BOT]);
        $user = $this->owner(connected: false);
        $connection = $this->connectionWith($user, self::NEW);
        $this->fakeLookup(self::NEW);

        $response = $this->actingAs($user)->patchJson("/dashboard/connections/{$connection->id}", [
            'attribute_values' => [[
                'attribute_definition_id' => $user->discord_user_id_definition_id,
                'identifier_list_value' => [self::NEW],
            ]],
        ])->assertOk();

        $this->assertTrue($response->json('discord_profiles.0.known'));
        $this->assertSame('plumfinch', $response->json('discord_profiles.0.username'));
    }

    public function test_saving_a_discord_id_without_a_bot_token_returns_a_placeholder(): void
    {
        config(['services.discord.bot_token' => null]);
        $user = $this->owner(connected: false);
        $connection = $this->connectionWith($user, self::NEW);

        $response = $this->actingAs($user)->patchJson("/dashboard/connections/{$connection->id}", [
            'attribute_values' => [[
                'attribute_definition_id' => $user->discord_user_id_definition_id,
                'identifier_list_value' => [self::NEW],
            ]],
        ])->assertOk();

        $this->assertFalse($response->json('discord_profiles.0.known'));
        $this->assertSame(self::NEW, $response->json('discord_profiles.0.discord_user_id'));
    }

    public function test_a_looked_up_person_never_appears_in_the_pending_list_even_when_connected(): void
    {
        config(['services.discord.bot_token' => self::BOT]);
        $user = $this->owner();
        $connection = $this->connectionWith($user, self::NEW);
        $this->fakeLookup(self::NEW);
        $this->actingAs($user)->postJson("/dashboard/connections/{$connection->id}/discord/lookup")->assertOk();

        $this->assertSame([], $this->actingAs($user)->getJson('/dashboard/discord/friends')->json('friends'));
        $this->assertSame(0, app(DiscordSyncService::class)->summary($user->refresh())['pending_friends']);
    }

    public function test_a_lookup_adds_the_names_as_highlight_tokens_and_keeps_a_friends_nickname(): void
    {
        config(['services.discord.bot_token' => self::BOT]);
        $user = $this->owner();
        $this->withHighlightField($user);
        $connection = $this->connectionWith($user, self::NEW);
        $this->fakeFriend(self::NEW, 'Plum Finch', 'plumfinch', 'Plummy');
        app(DiscordSyncService::class)->sync($user);
        $this->fakeLookup(self::NEW, body: ['id' => self::NEW, 'username' => 'plumfinch2', 'global_name' => 'Plum Finch the Second', 'avatar' => null]);

        $this->actingAs($user)->postJson("/dashboard/connections/{$connection->id}/discord/lookup")->assertOk();

        $friend = DiscordFriend::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('Plummy', $friend->nickname, 'a lookup never touches your nickname for a friend');
        $this->assertSame(DiscordFriend::CREATED, $friend->status, 'and does not change a friend status');
        $this->assertContains('Plum Finch the Second', $this->highlightTokens($user, $connection));
    }

    public function test_a_looked_up_person_who_is_later_a_friend_joins_the_friend_flow(): void
    {
        config(['services.discord.bot_token' => self::BOT]);
        $user = $this->owner();
        $connection = $this->connectionWith($user, self::NEW);
        $this->fakeLookup(self::NEW);
        $this->actingAs($user)->postJson("/dashboard/connections/{$connection->id}/discord/lookup")->assertOk();

        $this->fakeFriends([self::NEW]);
        app(DiscordSyncService::class)->sync($user);

        $this->assertSame([self::NEW => 'created'], $this->statuses($user), 'already on a connection, so settled');
        $this->assertSame(1, DiscordFriend::count());
    }

    public function test_the_lookup_needs_a_bot_token_and_a_discord_id(): void
    {
        $user = $this->owner();
        $connection = $this->connectionWith($user, self::NEW);

        $this->actingAs($user)->postJson("/dashboard/connections/{$connection->id}/discord/lookup")
            ->assertStatus(422)->assertJsonPath('message', 'Looking up Discord users is not set up on this server.');

        config(['services.discord.bot_token' => self::BOT]);
        $empty = Connection::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'name_ciphertext' => 'opaque']);
        $this->actingAs($user)->postJson("/dashboard/connections/{$empty->id}/discord/lookup")
            ->assertStatus(422)->assertJsonPath('message', 'This connection has no Discord user ID.');
    }

    public function test_a_missing_user_and_a_rejected_bot_token_are_reported_without_leaking_the_token(): void
    {
        config(['services.discord.bot_token' => self::BOT]);
        $user = $this->owner();
        $connection = $this->connectionWith($user, self::NEW);

        $this->fakeLookup(self::NEW, 404, ['message' => 'Unknown User']);
        $missing = $this->actingAs($user)->postJson("/dashboard/connections/{$connection->id}/discord/lookup")->assertStatus(422);
        $this->assertStringContainsString('no user with the ID', $missing->json('message'));

        $this->fakeLookup(self::NEW, 401, ['message' => 'Bot '.self::BOT]);
        $rejected = $this->actingAs($user)->postJson("/dashboard/connections/{$connection->id}/discord/lookup")->assertStatus(422);
        $this->assertStringContainsString('rejected the bot token', $rejected->json('message'));
        $this->assertStringNotContainsString(self::BOT, $rejected->getContent());
        $this->assertSame(0, DiscordFriend::count());
        $this->assertNotNull($user->refresh()->discord_access_token, 'a failed lookup never disconnects the owner');
    }

    public function test_one_owner_cannot_look_up_through_another_owners_connection(): void
    {
        config(['services.discord.bot_token' => self::BOT]);
        $other = $this->owner();
        $theirs = $this->connectionWith($other, self::NEW);
        Http::fake();

        $this->actingAs($this->owner())->postJson("/dashboard/connections/{$theirs->id}/discord/lookup")->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_the_summary_says_whether_a_lookup_is_possible(): void
    {
        $user = $this->owner();
        $this->assertFalse(app(DiscordSyncService::class)->summary($user)['lookup_available']);

        config(['services.discord.bot_token' => self::BOT]);
        $this->assertTrue(app(DiscordSyncService::class)->summary($user)['lookup_available']);
    }
}
