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
    private function fakeFriends(array $ids): void
    {
        $rows = array_map(fn ($id) => [
            'id' => '1', 'type' => 1, 'nickname' => $id === self::NEW ? 'Nick' : null, 'user_ignored' => false,
            'user' => ['id' => $id, 'username' => 'user'.substr($id, -2), 'global_name' => 'Friend '.substr($id, -2), 'avatar' => 'abc'],
        ], $ids);

        // Other relationship types (blocked 2, incoming request 3, outgoing request 4) are never imported.
        $rows[] = ['id' => '9', 'type' => 2, 'user' => ['id' => '100000000000000099', 'username' => 'blocked', 'global_name' => null, 'avatar' => null]];
        $rows[] = ['id' => '8', 'type' => 3, 'user' => ['id' => '100000000000000098', 'username' => 'incoming', 'global_name' => null, 'avatar' => null]];

        Http::fake(['discord.com/api/v10/users/@me/relationships' => Http::response($rows)]);
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

    public function test_only_mutual_friends_are_imported(): void
    {
        $user = $this->owner();
        $this->fakeFriends([self::OLD]);

        app(DiscordSyncService::class)->sync($user);

        $this->assertSame([self::OLD], array_map('strval', array_keys($this->statuses($user))));
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

    public function test_an_automatic_sync_is_queued_when_stale_and_not_again_during_the_cooldown(): void
    {
        Queue::fake();
        Cache::flush();
        $user = $this->owner();

        $this->actingAs($user)->get('/dashboard/connections');
        $this->actingAs($user)->get('/dashboard/connections');

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
}
