<?php

namespace Tests\Feature;

use App\Jobs\SyncVrcxData;
use App\Models\Connection;
use App\Models\ConnectionAttributeDefinition;
use App\Models\ConnectionAttributeValue;
use App\Models\ConnectionVrchatProfile;
use App\Models\ConnectionVrchatStay;
use App\Models\User;
use App\Services\Account\AccountExportService;
use App\Services\Vrcx\VrcxClient;
use App\Services\Vrcx\VrcxException;
use App\Services\Vrcx\VrcxSyncService;
use App\Support\VrchatUserId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipStream\ZipStream;

/** VRChat data pulled from the owner's own VRCX headless server (§0.2 tier). */
class VrcxIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const ALICE = 'usr_00000000-0000-0000-0000-00000000a11c';

    private const BOB = 'usr_00000000-0000-0000-0000-0000000000b0';

    private function definition(User $user, string $type): ConnectionAttributeDefinition
    {
        return ConnectionAttributeDefinition::create([
            'id' => (string) Str::uuid(), 'user_id' => $user->id, 'label_ciphertext' => 'opaque', 'type' => $type,
        ]);
    }

    /** @return array{0: User, 1: ConnectionAttributeDefinition} */
    private function configuredOwner(string $type = 'identifier_list'): array
    {
        $user = User::factory()->create();
        $definition = $this->definition($user, $type);
        $user->forceFill([
            'vrcx_base_url' => 'https://vrcx.example.test',
            'vrcx_api_key' => 'vrcx_key_secret',
            'vrchat_user_id_definition_id' => $definition->id,
        ])->save();

        return [$user, $definition];
    }

    private function link(User $user, ConnectionAttributeDefinition $definition, array|string $ids): Connection
    {
        $connection = Connection::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'name_ciphertext' => 'opaque']);
        ConnectionAttributeValue::create(is_array($ids)
            ? ['connection_id' => $connection->id, 'attribute_definition_id' => $definition->id, 'identifier_list_value' => $ids]
            : ['connection_id' => $connection->id, 'attribute_definition_id' => $definition->id, 'identifier_value' => $ids]);

        return $connection;
    }

    /** Http::fake() stubs accumulate (the first match wins), so every re-fake starts from a clean factory. */
    private function freshHttp(): Factory
    {
        Http::swap($factory = new Factory);

        return $factory;
    }

    private function interval(string $start, int $minutes, int $others = 0): array
    {
        $end = (new \DateTimeImmutable($start))->modify("+{$minutes} minutes")->format('Y-m-d\TH:i:s.v\Z');

        return ['userId' => self::ALICE, 'start' => $start, 'end' => $end, 'durationMs' => $minutes * 60000, 'otherPlayerCount' => $others];
    }

    /** A bulk time-together answer: every requested id gets the same intervals (`limit`/`offset` honoured per user). */
    private function together(array $intervals): \Closure
    {
        return fn ($request) => Http::response(['ok' => true, 'since' => '2026-08-01T00:00:00Z', 'users' => collect($request['ids'])
            ->mapWithKeys(fn ($id) => [$id => [
                'total' => count($intervals),
                'results' => array_slice($intervals, (int) ($request['offset'] ?? 0), (int) ($request['limit'] ?? 100)),
            ]])->all()]);
    }

    private function fakeVrcx(array $intervals): void
    {
        $this->freshHttp()->fake([
            '*/api/v1/users*' => Http::response(['ok' => true, 'missing' => [], 'users' => [
                self::ALICE => ['id' => self::ALICE, 'displayName' => 'Alice', 'profilePictureUrl' => 'https://img.test/a.png', 'avatarThumbnailUrl' => 'https://img.test/a-t.png'],
            ]]),
            '*/api/v1/time-together' => $this->together($intervals),
        ]);
    }

    public function test_sync_stores_profile_and_stats_for_identifier_list_and_single_identifier_fields(): void
    {
        foreach (['identifier_list', 'identifier'] as $type) {
            [$user, $definition] = $this->configuredOwner($type);
            $connection = $this->link($user, $definition, $type === 'identifier_list' ? ['  '.strtoupper(self::ALICE), 'not-an-id'] : self::ALICE);
            $this->fakeVrcx([$this->interval('2026-10-01T10:00:00.000Z', 60), $this->interval('2026-10-02T10:00:00.000Z', 30, 3)]);

            $this->assertTrue(app(VrcxSyncService::class)->sync($user));

            $profile = ConnectionVrchatProfile::where('connection_id', $connection->id)->sole();
            $this->assertSame(self::ALICE, $profile->vrchat_user_id);
            $this->assertSame('Alice', $profile->display_name);
            $this->assertSame('https://img.test/a.png', $profile->profile_picture_url);
            $this->assertSame(2, $profile->stats['stays']);
            $this->assertSame(90 * 60000, $profile->stats['total_ms']);
            $this->assertSame(60 * 60000, $profile->stats['one_on_one_ms']);
            $this->assertNotNull($user->refresh()->vrcx_synced_at);
        }
    }

    public function test_the_client_sends_the_bearer_key_and_normalizes_a_pasted_spec_url(): void
    {
        $this->assertSame('https://vrcx.test', VrcxClient::normalizeBaseUrl('https://vrcx.test/api/v1/openapi.json'));
        $this->assertSame('https://vrcx.test', VrcxClient::normalizeBaseUrl(' https://vrcx.test/api/v1/ '));
        $this->assertSame('https://vrcx.test/x', VrcxClient::normalizeBaseUrl('https://vrcx.test/x/'));

        [$user, $definition] = $this->configuredOwner();
        $this->link($user, $definition, [self::ALICE]);
        $this->fakeVrcx([]);

        app(VrcxSyncService::class)->sync($user);

        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/api/v1/users') && $r['ids'] === [self::ALICE]);
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer vrcx_key_secret') && str_starts_with($r->url(), 'https://vrcx.example.test/api/v1/'));
    }

    public function test_users_are_looked_up_in_bodies_of_at_most_fifty_ids(): void
    {
        [$user, $definition] = $this->configuredOwner();
        $ids = array_map(fn ($i) => sprintf('usr_00000000-0000-0000-0000-%012x', $i), range(1, 120));
        foreach ($ids as $id) {
            $this->link($user, $definition, [$id]);
        }
        $this->fakeVrcx([]);

        app(VrcxSyncService::class)->sync($user);

        $sizes = Http::recorded(fn ($request) => str_ends_with($request->url(), '/api/v1/users'))->map(fn ($pair) => count($pair[0]['ids']))->values()->all();
        $this->assertSame([50, 50, 20], $sizes);

        $together = Http::recorded(fn ($request) => str_ends_with($request->url(), '/api/v1/time-together'))->map(fn ($pair) => count($pair[0]['ids']))->values()->all();
        $this->assertSame([50, 50, 20], $together, 'time together is bulk too: one request per 50 accounts, not per account');
    }

    public function test_time_together_pages_only_the_users_whose_page_came_full(): void
    {
        [$user, $definition] = $this->configuredOwner();
        $this->link($user, $definition, [self::ALICE, self::BOB]);

        $alice = [];
        for ($i = 0; $i < 501; $i++) {
            $alice[] = $this->interval((new \DateTimeImmutable('2026-01-01T00:00:00Z'))->modify("+{$i} hours")->format('Y-m-d\TH:i:s.v\Z'), 1);
        }

        $this->freshHttp()->fake([
            '*/api/v1/users*' => Http::response(['ok' => true, 'missing' => [], 'users' => []]),
            '*/api/v1/time-together' => fn ($request) => Http::response(['ok' => true, 'since' => 'x', 'users' => collect($request['ids'])
                ->mapWithKeys(fn ($id) => [$id => ['total' => 0, 'results' => $id === self::ALICE
                    ? array_slice($alice, (int) $request['offset'], (int) $request['limit'])
                    : []]])->all()]),
        ]);

        app(VrcxSyncService::class)->sync($user);

        $calls = Http::recorded(fn ($request) => str_ends_with($request->url(), '/api/v1/time-together'))
            ->map(fn ($pair) => [$pair[0]['ids'], $pair[0]['offset']])->values()->all();
        $this->assertSame([[[self::ALICE, self::BOB], 0], [[self::ALICE], 500]], $calls);
        $this->assertSame(501, ConnectionVrchatStay::count());
    }

    public function test_resyncing_updates_in_place_and_keeps_history_beyond_the_window(): void
    {
        [$user, $definition] = $this->configuredOwner();
        $connection = $this->link($user, $definition, [self::ALICE]);
        $service = app(VrcxSyncService::class);

        $this->fakeVrcx([$this->interval('2026-07-01T10:00:00.000Z', 60), $this->interval('2026-10-01T10:00:00.000Z', 10)]);
        $service->sync($user);

        // The July stay has aged out of VRCX's window, and the October one grew since it was still open.
        $this->fakeVrcx([$this->interval('2026-10-01T10:00:00.000Z', 25)]);
        $service->sync($user);

        $this->assertSame(2, ConnectionVrchatStay::where('connection_id', $connection->id)->count());
        $stats = ConnectionVrchatProfile::where('connection_id', $connection->id)->sole()->stats;
        $this->assertSame(85 * 60000, $stats['total_ms']);
        $this->assertSame(2, $stats['stays']);
    }

    public function test_stays_a_world_switch_apart_are_stored_as_one_block_and_stay_one_block_on_resync(): void
    {
        [$user, $definition] = $this->configuredOwner();
        $connection = $this->link($user, $definition, [self::ALICE]);
        $service = app(VrcxSyncService::class);

        $this->fakeVrcx([$this->interval('2026-10-01T10:00:00.000Z', 60), $this->interval('2026-10-01T11:08:00.000Z', 52)]);
        $service->sync($user);

        $stay = ConnectionVrchatStay::where('connection_id', $connection->id)->sole();
        $this->assertSame('2026-10-01T10:00:00.000Z', $stay->payload['start']);
        $this->assertSame('2026-10-01T12:00:00.000Z', $stay->payload['end']);
        $this->assertSame(120 * 60000, $stay->payload['duration_ms']);
        $id = $stay->id;

        // Same data again: still the one row.
        $service->sync($user);
        $this->assertSame($id, ConnectionVrchatStay::where('connection_id', $connection->id)->sole()->id);

        // The first stay aged out of VRCX's window and a new one follows within the gap: the stored block grows, no duplicate.
        $this->fakeVrcx([$this->interval('2026-10-01T11:08:00.000Z', 52), $this->interval('2026-10-01T12:06:00.000Z', 30)]);
        $service->sync($user);

        $stay = ConnectionVrchatStay::where('connection_id', $connection->id)->sole();
        $this->assertSame($id, $stay->id);
        $this->assertSame('2026-10-01T10:00:00.000Z', $stay->payload['start']);
        $this->assertSame('2026-10-01T12:36:00.000Z', $stay->payload['end']);
        $this->assertSame(1, ConnectionVrchatProfile::where('connection_id', $connection->id)->sole()->stats['stays']);
    }

    public function test_stays_already_stored_apart_are_joined_by_the_next_sync(): void
    {
        [$user, $definition] = $this->configuredOwner();
        $connection = $this->link($user, $definition, [self::ALICE]);

        foreach (['2026-10-01T10:00:00.000Z' => '2026-10-01T11:00:00.000Z', '2026-10-01T11:07:00.000Z' => '2026-10-01T12:00:00.000Z'] as $start => $end) {
            ConnectionVrchatStay::create([
                'connection_id' => $connection->id,
                'vrchat_user_id_hash' => VrchatUserId::hash(self::ALICE),
                'dedupe_hash' => VrchatUserId::hash(self::ALICE.'|'.$start),
                'payload' => ['vrchat_user_id' => self::ALICE, 'start' => $start, 'end' => $end, 'duration_ms' => 1, 'other_player_count' => 0],
            ]);
        }

        $this->fakeVrcx([]);
        app(VrcxSyncService::class)->sync($user);

        $stay = ConnectionVrchatStay::where('connection_id', $connection->id)->sole();
        $this->assertSame('2026-10-01T12:00:00.000Z', $stay->payload['end']);
    }

    public function test_a_user_vrcx_has_not_cached_keeps_the_previous_name(): void
    {
        [$user, $definition] = $this->configuredOwner();
        $connection = $this->link($user, $definition, [self::ALICE]);
        $this->fakeVrcx([]);
        app(VrcxSyncService::class)->sync($user);

        $this->freshHttp()->fake([
            '*/api/v1/users*' => Http::response(['ok' => true, 'users' => [], 'missing' => [self::ALICE]]),
            '*/api/v1/time-together' => $this->together([]),
        ]);
        app(VrcxSyncService::class)->sync($user);

        $this->assertSame('Alice', ConnectionVrchatProfile::where('connection_id', $connection->id)->sole()->display_name);
    }

    public function test_removing_a_link_drops_its_cached_data_and_other_links_stay(): void
    {
        [$user, $definition] = $this->configuredOwner();
        $connection = $this->link($user, $definition, [self::ALICE, self::BOB]);
        $this->freshHttp()->fake([
            '*/api/v1/users*' => Http::response(['ok' => true, 'missing' => [], 'users' => []]),
            '*/api/v1/time-together' => $this->together([$this->interval('2026-10-01T10:00:00.000Z', 5)]),
        ]);
        $service = app(VrcxSyncService::class);
        $service->sync($user);
        $this->assertSame(2, ConnectionVrchatProfile::count());

        // Through the model: a bulk update() would bypass the encrypted cast.
        ConnectionAttributeValue::where('connection_id', $connection->id)->sole()->update(['identifier_list_value' => [self::BOB]]);
        $service->sync($user);

        $this->assertSame([self::BOB], ConnectionVrchatProfile::all()->pluck('vrchat_user_id')->all());
        $this->assertSame(1, ConnectionVrchatStay::count());
    }

    public function test_everything_stored_is_encrypted_at_rest(): void
    {
        [$user, $definition] = $this->configuredOwner();
        $this->link($user, $definition, [self::ALICE]);
        $this->fakeVrcx([$this->interval('2026-10-01T10:00:00.000Z', 60)]);
        app(VrcxSyncService::class)->sync($user);

        $dump = json_encode([
            DB::table('connection_vrchat_profiles')->get(),
            DB::table('connection_vrchat_stays')->get(),
            DB::table('users')->where('id', $user->id)->get(['vrcx_base_url', 'vrcx_api_key']),
        ]);

        foreach ([self::ALICE, 'Alice', 'img.test', '2026-10-01', 'vrcx_key_secret', 'vrcx.example.test'] as $plain) {
            $this->assertStringNotContainsString($plain, (string) $dump, "'{$plain}' is stored in plaintext");
        }
    }

    public function test_an_owner_only_ever_syncs_their_own_connections_against_their_own_server(): void
    {
        [$mine, $myDefinition] = $this->configuredOwner();
        $theirs = User::factory()->create();
        $theirDefinition = $this->definition($theirs, 'identifier_list');
        $theirConnection = $this->link($theirs, $theirDefinition, [self::BOB]);
        $this->fakeVrcx([]);

        app(VrcxSyncService::class)->sync($mine);

        $this->assertSame(0, ConnectionVrchatProfile::where('connection_id', $theirConnection->id)->count());
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'b0'));
    }

    public function test_failures_become_safe_messages_that_never_contain_the_key(): void
    {
        [$user, $definition] = $this->configuredOwner();
        $this->link($user, $definition, [self::ALICE]);

        foreach ([401 => 'rejected the API key', 403 => 'lacks a required scope', 500 => 'HTTP 500'] as $status => $expected) {
            $this->freshHttp()->fake(['*' => Http::response(['ok' => false, 'error' => 'vrcx_key_secret'], $status)]);

            try {
                app(VrcxSyncService::class)->sync($user);
                $this->fail('expected a VrcxException');
            } catch (VrcxException $e) {
                $this->assertStringContainsString($expected, $e->getMessage());
                $this->assertStringNotContainsString('vrcx_key_secret', $e->getMessage());
            }
        }

        $this->assertNull($user->refresh()->vrcx_synced_at, 'a failed sync is not recorded as a sync');
    }

    public function test_sync_needs_a_server_a_key_and_a_field(): void
    {
        $this->expectException(VrcxException::class);

        app(VrcxSyncService::class)->sync(User::factory()->create());
    }

    public function test_the_connections_page_queues_a_sync_only_when_stale_and_only_once_per_cooldown(): void
    {
        Queue::fake();
        [$user] = $this->configuredOwner();

        $this->actingAs($user)->get('/dashboard/connections')->assertOk();
        $this->actingAs($user)->get('/dashboard/connections')->assertOk();
        Queue::assertPushed(SyncVrcxData::class, 1);

        Cache::flush();
        $user->forceFill(['vrcx_synced_at' => now()->subMinute()])->save();
        $this->actingAs($user)->get('/dashboard/connections')->assertOk();
        Queue::assertPushed(SyncVrcxData::class, 1);
    }

    public function test_an_unconfigured_owner_is_never_synced(): void
    {
        Queue::fake();

        $this->actingAs(User::factory()->create())->get('/dashboard/connections')->assertOk();

        Queue::assertNothingPushed();
    }

    /** @param array<string, mixed> $override */
    private function fakeSpec(array $override = [], int $status = 200): void
    {
        $spec = array_replace_recursive([
            'openapi' => '3.1.0',
            'info' => ['title' => 'VRCX-Headless external API', 'version' => '1'],
            'paths' => ['/api/v1/users' => ['get' => [], 'post' => []], '/api/v1/time-together' => ['post' => []]],
        ], $override);

        $this->freshHttp()->fake(['*/api/v1/openapi.json' => Http::response($spec, $status)]);
    }

    public function test_a_new_url_must_serve_a_known_vrcx_spec(): void
    {
        $user = User::factory()->create();
        $cases = [
            'no api there' => [[], 404, 'no VRCX API'],
            'wrong version' => [['info' => ['version' => '2']], 200, 'version "2"'],
            'not vrcx' => [['info' => ['title' => 'Petstore']], 200, 'not the VRCX'],
            'missing operation' => [['paths' => ['/api/v1/users' => null]], 200, 'does not offer'],
            'no bulk POST' => [[], 200, 'POST /api/v1/users'],
        ];

        foreach ($cases as $name => [$override, $status, $message]) {
            $this->fakeSpec($override, $status);
            if ($name === 'no bulk POST') {
                $this->freshHttp()->fake(['*/api/v1/openapi.json' => Http::response([
                    'openapi' => '3.1.0', 'info' => ['title' => 'VRCX-Headless external API', 'version' => '1'],
                    'paths' => ['/api/v1/users' => ['get' => []], '/api/v1/time-together' => ['post' => []]],
                ])]);
            }
            if ($name === 'missing operation') {
                $this->freshHttp()->fake(['*/api/v1/openapi.json' => Http::response([
                    'openapi' => '3.1.0', 'info' => ['title' => 'VRCX-Headless external API', 'version' => '1'], 'paths' => ['/api/v1/me' => []],
                ])]);
            }

            $this->actingAs($user)->patch('/settings/vrcx', ['vrcx_base_url' => 'https://vrcx.example.test'])
                ->assertSessionHasErrors('vrcx_base_url');
            $this->assertStringContainsString($message, session('errors')->first('vrcx_base_url'), $name);
            $this->assertNull($user->refresh()->vrcx_base_url, $name);
        }

        $this->freshHttp()->fake(['*/api/v1/openapi.json' => Http::response('<html>nope</html>', 200)]);
        $this->actingAs($user)->patch('/settings/vrcx', ['vrcx_base_url' => 'https://vrcx.example.test'])
            ->assertSessionHasErrors('vrcx_base_url');

        $this->freshHttp()->fake(['*/api/v1/openapi.json' => fn () => throw new ConnectionException('down')]);
        $this->actingAs($user)->patch('/settings/vrcx', ['vrcx_base_url' => 'https://vrcx.example.test'])
            ->assertSessionHasErrors('vrcx_base_url');
    }

    public function test_an_unchanged_url_is_not_probed_again(): void
    {
        [$user] = $this->configuredOwner();
        $this->freshHttp()->fake();

        $this->actingAs($user)->patch('/settings/vrcx', ['vrcx_base_url' => 'https://vrcx.example.test/api/v1', 'vrcx_api_key' => 'new'])
            ->assertSessionHasNoErrors();

        Http::assertNothingSent();
    }

    public function test_settings_save_normalizes_the_url_keeps_the_key_when_blank_and_never_returns_it(): void
    {
        $user = User::factory()->create();
        $definition = $this->definition($user, 'identifier');
        $this->fakeSpec();

        $this->actingAs($user)->patch('/settings/vrcx', [
            'vrcx_base_url' => 'https://vrcx.example.test/api/v1/openapi.json',
            'vrcx_api_key' => 'vrcx_k1',
            'vrchat_user_id_definition_id' => $definition->id,
        ])->assertRedirect();

        $user->refresh();
        $this->assertSame('https://vrcx.example.test', $user->vrcx_base_url);
        $this->assertSame('vrcx_k1', $user->vrcx_api_key);
        $this->assertSame($definition->id, $user->vrchat_user_id_definition_id);

        $this->actingAs($user)->patch('/settings/vrcx', ['vrcx_api_key' => ''])->assertRedirect();
        $this->assertSame('vrcx_k1', $user->refresh()->vrcx_api_key);

        $this->actingAs($user)->get('/settings')->assertInertia(fn ($page) => $page
            ->where('vrcx.api_key', 'vrcx_k1')
            ->where('vrcx.base_url', 'https://vrcx.example.test')
            ->has('vrchatIdDefinitions', 1));
    }

    public function test_the_field_must_be_one_of_my_own_identifier_or_list_fields(): void
    {
        $user = User::factory()->create();

        foreach ([$this->definition($user, 'text'), $this->definition(User::factory()->create(), 'identifier')] as $bad) {
            $this->actingAs($user)->patch('/settings/vrcx', ['vrchat_user_id_definition_id' => $bad->id])
                ->assertSessionHasErrors('vrchat_user_id_definition_id');
        }

        foreach (['identifier', 'identifier_list'] as $type) {
            $this->actingAs($user)->patch('/settings/vrcx', ['vrchat_user_id_definition_id' => $this->definition($user, $type)->id])
                ->assertSessionHasNoErrors();
        }
    }

    public function test_the_selected_field_cannot_be_deleted(): void
    {
        [$user, $definition] = $this->configuredOwner();

        $this->actingAs($user)->deleteJson("/dashboard/connection-attribute-definitions/{$definition->id}")
            ->assertUnprocessable()
            ->assertJsonPath('errors.definition.0', fn ($m) => str_contains($m, 'VRChat user ID'));
    }

    public function test_changing_the_server_marks_the_data_stale_and_disconnect_forgets_the_key(): void
    {
        [$user] = $this->configuredOwner();
        $user->forceFill(['vrcx_synced_at' => now()])->save();
        $this->fakeSpec();

        $this->actingAs($user)->patch('/settings/vrcx', ['vrcx_base_url' => 'https://other.example.test'])->assertRedirect();
        $this->assertNull($user->refresh()->vrcx_synced_at);

        $this->actingAs($user)->delete('/settings/vrcx')->assertRedirect();
        $user->refresh();
        $this->assertNull($user->vrcx_api_key);
        $this->assertNull($user->vrcx_base_url);
    }

    public function test_sync_now_reports_the_summary_or_a_readable_error(): void
    {
        [$user, $definition] = $this->configuredOwner();
        $connection = $this->link($user, $definition, [self::ALICE]);
        $this->fakeVrcx([$this->interval('2026-10-01T10:00:00.000Z', 60)]);

        $this->actingAs($user)->postJson('/settings/vrcx/sync')
            ->assertOk()
            ->assertJsonPath("profiles.{$connection->id}.0.display_name", 'Alice')
            ->assertJsonPath("profiles.{$connection->id}.0.total_ms", 3600000);

        $this->freshHttp()->fake(['*' => Http::response([], 401)]);
        $this->actingAs($user)->postJson('/settings/vrcx/sync')->assertUnprocessable()->assertJsonPath('message', fn ($m) => str_contains($m, 'API key'));
    }

    /**
     * The single-user call (GET /api/v1/users/{userId}), which is what lets VRCX fall back to VRChat for a user it has
     * not cached. $users maps an id to its user object, or to null for "VRCX does not know them" (404); a closure
     * answers a request itself instead. POST /api/v1/users is deliberately not stubbed into success: it never falls back.
     *
     * @param  array<string, array<string, mixed>|null>|\Closure  $users
     */
    private function fakeVrcxSingleUsers(array|\Closure $users, ?\Closure $together = null): void
    {
        $this->freshHttp()->fake([
            '*/api/v1/users/*' => fn ($request) => $users instanceof \Closure
                ? $users($request)
                : (($user = $users[basename(parse_url($request->url(), PHP_URL_PATH))] ?? null) !== null
                    ? Http::response(['ok' => true, 'user' => $user])
                    : Http::response(['ok' => false, 'error' => 'User is not in the cache'], 404)),
            '*/api/v1/time-together' => $together ?? $this->together([$this->interval('2026-10-01T10:00:00.000Z', 60)]),
            '*' => Http::response(['ok' => false, 'error' => 'unexpected request'], 500),
        ]);
    }

    private function aliceUser(array $over = []): array
    {
        return $over + ['id' => self::ALICE, 'displayName' => 'Alice', 'profilePictureUrl' => 'https://img.test/a.png', 'avatarThumbnailUrl' => 'https://img.test/a-t.png', 'discordId' => null];
    }

    public function test_connection_sync_fetches_each_id_with_the_single_user_call_and_leaves_other_connections_alone(): void
    {
        [$user, $definition] = $this->configuredOwner();
        $mine = $this->link($user, $definition, [self::ALICE, self::BOB]);
        $other = $this->link($user, $definition, ['usr_00000000-0000-0000-0000-0000000000c0']);
        $this->fakeVrcxSingleUsers([self::ALICE => $this->aliceUser(['note' => 'met at a meetup'])]);

        $response = $this->actingAs($user)->postJson("/dashboard/connections/{$mine->id}/vrchat/sync")
            ->assertOk()
            ->assertJsonPath("profiles.{$mine->id}.0.display_name", 'Alice');

        $this->assertCount(2, Http::recorded(fn ($request) => $request->method() === 'GET' && str_contains($request->url(), '/api/v1/users/usr_')));
        $this->assertCount(0, Http::recorded(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/api/v1/users')));
        $this->assertSame('met at a meetup', ConnectionVrchatProfile::where('connection_id', $mine->id)->where('vrchat_user_id_hash', VrchatUserId::hash(self::ALICE))->value('vrchat_note'));
        $this->assertSame(0, ConnectionVrchatProfile::where('connection_id', $other->id)->count());
        $this->assertNull($user->refresh()->vrcx_synced_at);

        // Bob is unknown to VRCX: his id is kept, with no profile, and the owner is told once.
        $this->assertCount(1, $response->json('warnings'));
        $this->assertStringContainsString('does not know one of this connection', $response->json('warnings.0'));
        $this->assertNull(ConnectionVrchatProfile::where('connection_id', $mine->id)->where('vrchat_user_id_hash', VrchatUserId::hash(self::BOB))->value('display_name'));
    }

    public function test_connection_sync_turns_a_vrcx_server_error_into_a_warning_and_carries_on(): void
    {
        [$user, $definition] = $this->configuredOwner();
        $connection = $this->link($user, $definition, [self::ALICE, self::BOB]);
        $this->fakeVrcxSingleUsers(
            [self::ALICE => $this->aliceUser(), self::BOB => $this->aliceUser(['id' => self::BOB, 'displayName' => 'Bob'])],
            fn ($request) => Http::response(['ok' => false, 'error' => 'Internal error'], 500),
        );

        $response = $this->actingAs($user)->postJson("/dashboard/connections/{$connection->id}/vrchat/sync")->assertOk();

        $this->assertSame(['Alice', 'Bob'], ConnectionVrchatProfile::where('connection_id', $connection->id)->get()->pluck('display_name')->sort()->values()->all());
        $this->assertCount(1, $response->json('warnings'));
        $this->assertStringContainsString('Time together could not be fetched', $response->json('warnings.0'));
        $this->assertStringNotContainsString(self::ALICE, $response->json('warnings.0'));
    }

    public function test_connection_sync_keeps_the_previous_profile_when_vrcx_fails_on_the_user(): void
    {
        [$user, $definition] = $this->configuredOwner();
        $connection = $this->link($user, $definition, [self::ALICE]);
        $this->fakeVrcxSingleUsers([self::ALICE => $this->aliceUser()]);
        $this->actingAs($user)->postJson("/dashboard/connections/{$connection->id}/vrchat/sync")->assertOk();

        $this->fakeVrcxSingleUsers(fn ($request) => Http::response(['ok' => false, 'error' => 'Internal error'], 500));
        $response = $this->actingAs($user)->postJson("/dashboard/connections/{$connection->id}/vrchat/sync")->assertOk();

        $this->assertSame('Alice', ConnectionVrchatProfile::where('connection_id', $connection->id)->sole()->display_name);
        $this->assertStringContainsString('the previous ones were kept', $response->json('warnings.0'));
        $this->assertStringNotContainsString(self::ALICE, $response->json('warnings.0'));
    }

    public function test_connection_sync_stops_on_a_bad_key_without_putting_the_vrchat_id_in_the_message(): void
    {
        [$user, $definition] = $this->configuredOwner();
        $connection = $this->link($user, $definition, [self::ALICE]);
        $this->fakeVrcxSingleUsers(fn ($request) => Http::response(['ok' => false, 'error' => 'Unauthorized'], 401));

        $this->actingAs($user)->postJson("/dashboard/connections/{$connection->id}/vrchat/sync")
            ->assertUnprocessable()
            ->assertJsonPath('message', fn ($message) => str_contains($message, 'API key') && ! str_contains($message, 'usr_'));
    }

    public function test_the_client_reads_one_user_and_maps_every_not_found_to_null(): void
    {
        $client = new VrcxClient('https://vrcx.example.test', 'vrcx_key_secret');

        $this->fakeVrcxSingleUsers([self::ALICE => $this->aliceUser(['discordId' => '123456789012345678'])]);
        $found = $client->user(self::ALICE);
        $this->assertSame('Alice', $found['displayName']);
        $this->assertSame('123456789012345678', $found['discordId']);
        $this->assertNull($found['note']); // No notes:read in this response, so no note field.

        $this->assertNull($client->user(self::BOB)); // "User is not in the cache"

        $this->freshHttp()->fake(['*' => Http::response(['ok' => false, 'error' => 'Not found'], 404)]);
        $this->assertNull($client->user(self::ALICE)); // The other 404 (malformed id, or VRChat said no).
    }

    public function test_the_client_never_puts_a_vrchat_id_in_a_single_user_error(): void
    {
        $client = new VrcxClient('https://vrcx.example.test', 'vrcx_key_secret');
        $this->freshHttp()->fake(['*' => Http::response(['ok' => false, 'error' => 'Internal error'], 500)]);

        try {
            $client->user(self::ALICE);
            $this->fail('expected a VrcxException');
        } catch (VrcxException $e) {
            $this->assertTrue($e->isServerError());
            $this->assertStringContainsString('GET /api/v1/users/{userId}', $e->getMessage());
            $this->assertStringNotContainsString(self::ALICE, $e->getMessage());
        }
    }

    public function test_connection_sync_is_refused_for_someone_elses_connection(): void
    {
        [$user, $definition] = $this->configuredOwner();
        [$stranger, $strangerDefinition] = $this->configuredOwner();
        $theirs = $this->link($stranger, $strangerDefinition, [self::ALICE]);

        $this->actingAs($user)->postJson("/dashboard/connections/{$theirs->id}/vrchat/sync")->assertNotFound();
    }

    public function test_stays_are_listed_newest_first_and_only_for_my_own_connection(): void
    {
        [$user, $definition] = $this->configuredOwner();
        $connection = $this->link($user, $definition, [self::ALICE]);
        $this->fakeVrcx([$this->interval('2026-10-01T10:00:00.000Z', 5), $this->interval('2026-10-03T10:00:00.000Z', 7)]);
        app(VrcxSyncService::class)->sync($user);

        $this->actingAs($user)->getJson("/dashboard/connections/{$connection->id}/vrchat/stays")
            ->assertOk()
            ->assertJsonPath('stays.0.start', '2026-10-03T10:00:00.000Z')
            ->assertJsonCount(2, 'stays');

        $this->actingAs(User::factory()->create())->getJson("/dashboard/connections/{$connection->id}/vrchat/stays")->assertNotFound();
    }

    public function test_the_export_service_includes_vrchat_data_but_never_the_api_key(): void
    {
        [$user, $definition] = $this->configuredOwner();
        $connection = $this->link($user, $definition, [self::ALICE]);
        $this->fakeVrcx([$this->interval('2026-10-01T10:00:00.000Z', 60)]);
        app(VrcxSyncService::class)->sync($user);

        $files = [];
        $zip = \Mockery::mock(ZipStream::class);
        $zip->shouldReceive('addFile')->andReturnUsing(function (string $name, string $data) use (&$files) {
            $files[$name] = $data;
        });
        app(AccountExportService::class)->build($zip, $user);

        $profiles = json_decode($files['connections/vrchat-profiles.json'], true);
        $this->assertSame('server-decrypted', $profiles['tier']);
        $this->assertSame(self::ALICE, $profiles['records'][0]['vrchat_user_id']);
        $this->assertSame($connection->id, $profiles['records'][0]['connection_id']);
        $this->assertSame('Alice', $profiles['records'][0]['display_name']);

        $stays = json_decode($files['connections/vrchat-stays.json'], true);
        $this->assertSame(3600000, $stays['records'][0]['duration_ms']);

        $this->assertStringNotContainsString('vrcx_key_secret', implode("\n", $files));
        $this->assertSame('https://vrcx.example.test', json_decode($files['account/profile.json'], true)['records'][0]['vrcx_base_url']);
    }

    public function test_a_cloudflare_block_is_reported_as_a_firewall_not_a_missing_api_or_a_bad_key(): void
    {
        $user = User::factory()->create();
        $blocked = fn () => Http::response('<html><title>Attention Required! | Cloudflare</title></html>', 403, ['Server' => 'cloudflare', 'cf-mitigated' => 'challenge']);

        $this->freshHttp()->fake(['*/api/v1/openapi.json' => $blocked]);
        $this->actingAs($user)->patch('/settings/vrcx', ['vrcx_base_url' => 'https://vrcx.example.test'])->assertSessionHasErrors('vrcx_base_url');
        $this->assertStringContainsString('firewall', session('errors')->first('vrcx_base_url'));
        $this->assertStringNotContainsString('no VRCX API', session('errors')->first('vrcx_base_url'));

        [$owner, $definition] = $this->configuredOwner();
        $this->link($owner, $definition, [self::ALICE]);
        $this->freshHttp()->fake(['*' => $blocked]);
        try {
            app(VrcxSyncService::class)->sync($owner);
            $this->fail('expected a VrcxException');
        } catch (VrcxException $e) {
            $this->assertStringContainsString('firewall', $e->getMessage());
            $this->assertStringNotContainsString('scope', $e->getMessage());
        }
    }

    public function test_vrcx_own_403_and_a_plain_403_are_not_mistaken_for_a_firewall(): void
    {
        [$owner, $definition] = $this->configuredOwner();
        $this->link($owner, $definition, [self::ALICE]);

        $this->freshHttp()->fake(['*' => Http::response(['ok' => false, 'error' => 'Key lacks the required scope'], 403, ['Server' => 'cloudflare'])]);
        try {
            app(VrcxSyncService::class)->sync($owner);
            $this->fail('expected a VrcxException');
        } catch (VrcxException $e) {
            $this->assertStringContainsString('scope', $e->getMessage());
        }

        $this->freshHttp()->fake(['*/api/v1/openapi.json' => Http::response('nope', 403)]);
        $this->actingAs(User::factory()->create())->patch('/settings/vrcx', ['vrcx_base_url' => 'https://vrcx.example.test'])->assertSessionHasErrors('vrcx_base_url');
        $this->assertStringContainsString('HTTP 403', session('errors')->first('vrcx_base_url'));
    }

    public function test_the_discord_field_must_be_my_own_identifier_or_list_field_and_not_the_vrchat_one(): void
    {
        [$user, $vrchat] = $this->configuredOwner();
        $mine = $this->definition($user, 'identifier');
        $theirs = $this->definition(User::factory()->create(), 'identifier');
        $text = $this->definition($user, 'text');

        foreach ([$theirs, $text] as $bad) {
            $this->actingAs($user)->patch('/settings/vrcx', ['discord_user_id_definition_id' => $bad->id])->assertSessionHasErrors('discord_user_id_definition_id');
        }

        // One field holds one kind of id, whichever of the two is being changed.
        $this->actingAs($user)->patch('/settings/vrcx', ['discord_user_id_definition_id' => $vrchat->id])->assertSessionHasErrors('discord_user_id_definition_id');
        $this->assertNull($user->refresh()->discord_user_id_definition_id);

        $this->actingAs($user)->patch('/settings/vrcx', ['discord_user_id_definition_id' => $mine->id])->assertSessionHasNoErrors();
        $this->assertSame($mine->id, $user->refresh()->discord_user_id_definition_id);

        $this->actingAs($user)->patch('/settings/vrcx', ['vrchat_user_id_definition_id' => $mine->id])->assertSessionHasErrors('vrchat_user_id_definition_id');
        $this->assertSame($vrchat->id, $user->refresh()->vrchat_user_id_definition_id);

        $this->actingAs($user)->patch('/settings/vrcx', ['discord_user_id_definition_id' => null])->assertSessionHasNoErrors();
        $this->assertNull($user->refresh()->discord_user_id_definition_id);
    }

    public function test_the_selected_discord_field_cannot_be_deleted_and_the_settings_page_reports_it(): void
    {
        [$user] = $this->configuredOwner();
        $discord = $this->definition($user, 'identifier_list');
        $user->forceFill(['discord_user_id_definition_id' => $discord->id])->save();

        $this->actingAs($user)->deleteJson("/dashboard/connection-attribute-definitions/{$discord->id}")
            ->assertUnprocessable()
            ->assertJsonPath('errors.definition.0', fn ($m) => str_contains($m, 'Discord user ID'));

        $this->actingAs($user)->get('/settings')->assertInertia(fn ($page) => $page->where('vrcx.discord_user_id_definition_id', $discord->id));
    }

    public function test_the_discord_id_vrcx_reports_is_stored_encrypted_shown_and_never_cleared(): void
    {
        [$user, $definition] = $this->configuredOwner();
        $connection = $this->link($user, $definition, [self::ALICE]);
        $service = app(VrcxSyncService::class);

        $withDiscord = fn (mixed $discord) => $this->freshHttp()->fake([
            '*/api/v1/users*' => Http::response(['ok' => true, 'missing' => [], 'users' => [self::ALICE => [
                'id' => self::ALICE, 'displayName' => 'Alice', 'profilePictureUrl' => '', 'avatarThumbnailUrl' => '', 'discordId' => $discord,
            ]]]),
            '*/api/v1/time-together*' => $this->together([]),
        ]);

        $withDiscord('123456789012345678');
        $service->sync($user);

        $this->assertSame('123456789012345678', ConnectionVrchatProfile::where('connection_id', $connection->id)->sole()->discord_id);
        $this->assertStringNotContainsString('123456789012345678', (string) DB::table('connection_vrchat_profiles')->value('discord_id'));
        $this->assertSame('123456789012345678', $service->summary($user->refresh())['profiles'][$connection->id][0]['discord_id']);

        // VRCX no longer reports one (null, empty, or junk): the stored id stays.
        foreach ([null, '', 'not-a-snowflake'] as $nothing) {
            $withDiscord($nothing);
            $service->sync($user->refresh());
            $this->assertSame('123456789012345678', ConnectionVrchatProfile::where('connection_id', $connection->id)->sole()->discord_id);
        }

        // A different id replaces it.
        $withDiscord('987654321098765432');
        $service->sync($user->refresh());
        $this->assertSame('987654321098765432', ConnectionVrchatProfile::where('connection_id', $connection->id)->sole()->discord_id);
    }

    public function test_the_export_carries_the_discord_id_and_field_choice(): void
    {
        [$user, $definition] = $this->configuredOwner();
        $discord = $this->definition($user, 'identifier');
        $user->forceFill(['discord_user_id_definition_id' => $discord->id])->save();
        $connection = $this->link($user, $definition, [self::ALICE]);
        ConnectionVrchatProfile::create([
            'connection_id' => $connection->id, 'vrchat_user_id_hash' => VrchatUserId::hash(self::ALICE),
            'vrchat_user_id' => self::ALICE, 'discord_id' => '123456789012345678',
        ]);

        $files = [];
        $zip = \Mockery::mock(ZipStream::class);
        $zip->shouldReceive('addFile')->andReturnUsing(function (string $name, string $data) use (&$files) {
            $files[$name] = $data;
        });
        app(AccountExportService::class)->build($zip, $user);

        $this->assertSame('123456789012345678', json_decode($files['connections/vrchat-profiles.json'], true)['records'][0]['discord_id']);
        $this->assertSame($discord->id, json_decode($files['account/profile.json'], true)['records'][0]['discord_user_id_definition_id']);
    }

    /** @param array<string, string|null> $notes VRChat id => the note VRCX reports (null/absent: none) */
    private function fakeNotes(array $notes): void
    {
        $this->freshHttp()->fake([
            '*/api/v1/users*' => Http::response(['ok' => true, 'missing' => [], 'users' => collect($notes)->map(fn ($note, $id) => array_filter([
                'id' => $id, 'displayName' => 'Someone', 'profilePictureUrl' => '', 'avatarThumbnailUrl' => '', 'discordId' => null, 'note' => $note,
            ], fn ($v, $k) => $k !== 'note' || $v !== null, ARRAY_FILTER_USE_BOTH))->all()]),
            '*/api/v1/time-together*' => $this->together([]),
        ]);
    }

    public function test_a_vrchat_note_is_kept_encrypted_until_it_is_carried_over(): void
    {
        [$user, $definition] = $this->configuredOwner();
        $connection = $this->link($user, $definition, [self::ALICE]);
        $service = app(VrcxSyncService::class);

        $this->fakeNotes([self::ALICE => "  Met at the fox meetup\nlikes tea  "]);
        $service->sync($user);

        $profile = ConnectionVrchatProfile::where('connection_id', $connection->id)->sole();
        $this->assertSame("Met at the fox meetup\nlikes tea", $profile->vrchat_note);
        $this->assertStringNotContainsString('fox meetup', (string) DB::table('connection_vrchat_profiles')->value('vrchat_note'));
        $this->assertSame("Met at the fox meetup\nlikes tea", $service->summary($user->refresh())['profiles'][$connection->id][0]['note']);

        // A sync where no note comes back (no notes:read scope, or the note was removed) changes nothing.
        $this->fakeNotes([self::ALICE => null]);
        $service->sync($user->refresh());
        $this->assertSame("Met at the fox meetup\nlikes tea", $profile->refresh()->vrchat_note);
    }

    public function test_confirming_the_import_drops_the_plaintext_and_the_same_note_is_never_offered_again(): void
    {
        [$user, $definition] = $this->configuredOwner();
        $connection = $this->link($user, $definition, [self::ALICE]);
        $service = app(VrcxSyncService::class);
        $this->fakeNotes([self::ALICE => 'Likes tea']);
        $service->sync($user);

        $this->actingAs($user)->postJson('/dashboard/vrchat/notes/imported', ['imported' => [
            ['connection_id' => $connection->id, 'vrchat_user_id' => strtoupper(self::ALICE), 'note' => ' Likes tea '],
        ]])->assertOk()->assertJson(['settled' => 1]);

        $profile = ConnectionVrchatProfile::where('connection_id', $connection->id)->sole();
        $this->assertNull($profile->vrchat_note, 'the server no longer holds the note itself');
        $this->assertNotNull($profile->note_imported_hash);
        $this->assertStringNotContainsString('Likes tea', json_encode(DB::table('connection_vrchat_profiles')->get()));
        $this->assertNull($service->summary($user->refresh())['profiles'][$connection->id][0]['note']);

        // The same note comes back from VRCX on every sync: it is not stored, so not offered, again.
        $service->sync($user->refresh());
        $this->assertNull($profile->refresh()->vrchat_note);

        // A note that was changed in VRChat is offered once more.
        $this->fakeNotes([self::ALICE => 'Likes tea and cake']);
        $service->sync($user->refresh());
        $this->assertSame('Likes tea and cake', $profile->refresh()->vrchat_note);
    }

    public function test_an_import_confirmation_for_a_note_that_changed_meanwhile_or_is_not_mine_settles_nothing(): void
    {
        [$user, $definition] = $this->configuredOwner();
        $connection = $this->link($user, $definition, [self::ALICE]);
        $this->fakeNotes([self::ALICE => 'The new note']);
        app(VrcxSyncService::class)->sync($user);

        // The browser merged an older version of the note.
        $this->actingAs($user)->postJson('/dashboard/vrchat/notes/imported', ['imported' => [
            ['connection_id' => $connection->id, 'vrchat_user_id' => self::ALICE, 'note' => 'The old note'],
        ]])->assertOk()->assertJson(['settled' => 0]);
        $this->assertSame('The new note', ConnectionVrchatProfile::where('connection_id', $connection->id)->sole()->vrchat_note);

        // Another owner cannot settle it either.
        $this->actingAs(User::factory()->create())->postJson('/dashboard/vrchat/notes/imported', ['imported' => [
            ['connection_id' => $connection->id, 'vrchat_user_id' => self::ALICE, 'note' => 'The new note'],
        ]])->assertOk()->assertJson(['settled' => 0]);
        $this->assertSame('The new note', ConnectionVrchatProfile::where('connection_id', $connection->id)->sole()->vrchat_note);
    }

    public function test_the_export_lists_notes_still_waiting_to_be_carried_over(): void
    {
        [$user, $definition] = $this->configuredOwner();
        $this->link($user, $definition, [self::ALICE]);
        $this->fakeNotes([self::ALICE => 'Waiting note']);
        app(VrcxSyncService::class)->sync($user);

        $files = [];
        $zip = \Mockery::mock(ZipStream::class);
        $zip->shouldReceive('addFile')->andReturnUsing(function (string $name, string $data) use (&$files) {
            $files[$name] = $data;
        });
        app(AccountExportService::class)->build($zip, $user);

        $this->assertSame('Waiting note', json_decode($files['connections/vrchat-profiles.json'], true)['records'][0]['vrchat_note_awaiting_import']);
    }

    public function test_the_summary_names_the_discord_field_so_the_list_can_mark_connections(): void
    {
        [$user] = $this->configuredOwner();
        $discord = $this->definition($user, 'identifier');
        $this->assertNull(app(VrcxSyncService::class)->summary($user)['discord_field_id']);

        $user->forceFill(['discord_user_id_definition_id' => $discord->id])->save();
        $this->assertSame($discord->id, app(VrcxSyncService::class)->summary($user->refresh())['discord_field_id']);
    }

    private const DISCORD = '123456789012345678';

    /** @param string|null $discord what VRCX reports for ALICE (null: nothing) */
    private function fakeDiscord(?string $discord): void
    {
        $this->freshHttp()->fake([
            '*/api/v1/users*' => Http::response(['ok' => true, 'missing' => [], 'users' => [self::ALICE => array_filter([
                'id' => self::ALICE, 'displayName' => 'Alice', 'profilePictureUrl' => '', 'avatarThumbnailUrl' => '', 'discordId' => $discord,
            ], fn ($v, $k) => $k !== 'discordId' || $v !== null, ARRAY_FILTER_USE_BOTH)]]),
            '*/api/v1/time-together*' => $this->together([]),
        ]);
    }

    private function discordValue(Connection $connection, ConnectionAttributeDefinition $field): mixed
    {
        $row = ConnectionAttributeValue::where('connection_id', $connection->id)->where('attribute_definition_id', $field->id)->first();

        return $row?->identifier_list_value ?? $row?->identifier_value;
    }

    public function test_a_sync_fills_the_discord_list_field_and_is_idempotent(): void
    {
        [$user, $definition] = $this->configuredOwner();
        $field = $this->definition($user, 'identifier_list');
        $user->forceFill(['discord_user_id_definition_id' => $field->id])->save();
        $connection = $this->link($user, $definition, [self::ALICE]);
        ConnectionAttributeValue::create(['connection_id' => $connection->id, 'attribute_definition_id' => $field->id, 'identifier_list_value' => ['alt#0001']]);
        $this->fakeDiscord(self::DISCORD);
        $service = app(VrcxSyncService::class);

        $service->sync($user->refresh());
        $service->sync($user->refresh());
        $service->sync($user->refresh());

        $this->assertSame(['alt#0001', self::DISCORD], $this->discordValue($connection, $field), 'appended once, nothing removed');
    }

    public function test_a_single_identifier_discord_field_is_filled_when_empty_and_never_overwritten(): void
    {
        [$user, $definition] = $this->configuredOwner();
        $field = $this->definition($user, 'identifier');
        $user->forceFill(['discord_user_id_definition_id' => $field->id])->save();
        $empty = $this->link($user, $definition, [self::ALICE]);
        $this->fakeDiscord(self::DISCORD);
        $service = app(VrcxSyncService::class);

        $service->sync($user->refresh());
        $this->assertSame(self::DISCORD, $this->discordValue($empty, $field));
        $service->sync($user->refresh());
        $this->assertSame(self::DISCORD, $this->discordValue($empty, $field));

        $typed = $this->link($user, $definition, [self::ALICE]);
        ConnectionAttributeValue::create(['connection_id' => $typed->id, 'attribute_definition_id' => $field->id, 'identifier_value' => 'typed by hand']);
        $service->sync($user->refresh());
        $this->assertSame('typed by hand', $this->discordValue($typed, $field), 'a value someone entered is never replaced');
    }

    public function test_choosing_the_field_after_the_id_was_learned_fills_it_at_once_and_on_the_next_sync_even_if_vrcx_reports_nothing(): void
    {
        [$user, $definition] = $this->configuredOwner();
        $connection = $this->link($user, $definition, [self::ALICE]);
        $this->fakeDiscord(self::DISCORD);
        app(VrcxSyncService::class)->sync($user);   // learns the id; no Discord field chosen yet
        $field = $this->definition($user, 'identifier');
        $this->assertNull($this->discordValue($connection, $field));

        // Choosing the field backfills from what is stored, with no request to VRCX.
        $this->freshHttp()->fake();
        $this->actingAs($user->refresh())->patch('/settings/vrcx', ['discord_user_id_definition_id' => $field->id])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn ($status) => str_contains($status, 'Added Discord IDs to 1 connection'));
        Http::assertNothingSent();
        $this->assertSame(self::DISCORD, $this->discordValue($connection, $field));

        // Emptied again, and VRCX reports no Discord ID this time: the next sync still fills it from the stored one.
        ConnectionAttributeValue::where('connection_id', $connection->id)->where('attribute_definition_id', $field->id)->delete();
        $this->fakeDiscord(null);
        app(VrcxSyncService::class)->sync($user->refresh());
        $this->assertSame(self::DISCORD, $this->discordValue($connection, $field));
    }

    public function test_nothing_is_written_without_a_discord_field_or_onto_another_owners_connections(): void
    {
        [$user, $definition] = $this->configuredOwner();
        $connection = $this->link($user, $definition, [self::ALICE]);
        $this->fakeDiscord(self::DISCORD);
        app(VrcxSyncService::class)->sync($user);
        $this->assertSame(0, ConnectionAttributeValue::where('connection_id', $connection->id)->count() - 1, 'only the VRChat id value exists');

        // Another owner choosing a Discord field never touches this owner's connections.
        $other = User::factory()->create();
        $otherField = $this->definition($other, 'identifier_list');
        $other->forceFill(['discord_user_id_definition_id' => $otherField->id])->save();
        $this->assertSame(0, app(VrcxSyncService::class)->backfillDiscordField($other->refresh()));
        $this->assertSame(0, ConnectionAttributeValue::where('attribute_definition_id', $otherField->id)->count());
    }

    public function test_saving_a_connection_fills_the_discord_field_too(): void
    {
        [$user, $definition] = $this->configuredOwner();
        $field = $this->definition($user, 'identifier_list');
        $user->forceFill(['discord_user_id_definition_id' => $field->id])->save();
        $connection = Connection::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'name_ciphertext' => 'opaque']);
        $this->fakeDiscord(self::DISCORD);

        $response = $this->actingAs($user->refresh())->patchJson("/dashboard/connections/{$connection->id}", ['attribute_values' => [
            ['attribute_definition_id' => $definition->id, 'identifier_list_value' => [self::ALICE]],
        ]])->assertOk();

        $this->assertSame([self::DISCORD], $this->discordValue($connection, $field));
        $this->assertContains(self::DISCORD, collect($response->json('attribute_values'))->firstWhere('attribute_definition_id', $field->id)['identifier_list_value']);
    }

    public function test_a_server_error_names_the_request_and_what_vrcx_said_without_ever_leaking_the_key(): void
    {
        [$user, $definition] = $this->configuredOwner();
        $this->link($user, $definition, [self::ALICE]);

        $this->freshHttp()->fake([
            '*/api/v1/time-together*' => Http::response(['ok' => false, 'error' => 'Cannot read property note of undefined (key vrcx_key_secret)'], 500),
            '*' => Http::response(['ok' => true, 'users' => [], 'missing' => []], 200),
        ]);

        try {
            app(VrcxSyncService::class)->sync($user);
            $this->fail('expected a VrcxException');
        } catch (VrcxException $e) {
            $this->assertStringContainsString('POST /api/v1/time-together', $e->getMessage());
            $this->assertStringContainsString('HTTP 500', $e->getMessage());
            $this->assertStringContainsString('Cannot read property note of undefined', $e->getMessage());
            $this->assertStringNotContainsString('vrcx_key_secret', $e->getMessage());
        }

        // Without any text from VRCX the message still names the request.
        $this->freshHttp()->fake(['*' => Http::response('Internal Server Error', 500)]);
        try {
            app(VrcxSyncService::class)->sync($user);
            $this->fail('expected a VrcxException');
        } catch (VrcxException $e) {
            $this->assertMatchesRegularExpression('/answered POST \/api\/v1\/time-together with HTTP 500\.$/', $e->getMessage());
        }
    }

    /** Every /users request 500s the way VRCX 22.0 does for a key with notes:read; time together still works. */
    private function fakeUsersFailing(): void
    {
        $this->freshHttp()->fake([
            '*/api/v1/users*' => Http::response(['ok' => false, 'error' => 'Internal error.'], 500),
            '*/api/v1/time-together*' => $this->together([$this->interval('2026-10-01T10:00:00.000Z', 5)]),
        ]);
    }

    /** @return list<string> */
    private function linkMany(User $user, ConnectionAttributeDefinition $definition, int $count): array
    {
        $ids = array_map(fn ($i) => sprintf('usr_00000000-0000-0000-0000-%012x', $i), range(1, $count));
        foreach ($ids as $id) {
            $this->link($user, $definition, [$id]);
        }

        return $ids;
    }

    public function test_a_users_endpoint_that_fails_with_a_server_error_is_a_warning_not_a_dead_sync(): void
    {
        [$user, $definition] = $this->configuredOwner();
        $this->linkMany($user, $definition, 50);
        $this->fakeUsersFailing();

        $service = app(VrcxSyncService::class);
        $this->assertTrue($service->sync($user));

        $warnings = $service->warnings();
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('Internal error.', $warnings[0]);
        $this->assertStringContainsString('Time together and friends were still synced', $warnings[0]);
        $this->assertSame(50, ConnectionVrchatStay::count());
        $this->assertNotNull($user->refresh()->vrcx_synced_at);

        $this->assertSame(1, Http::recorded(fn ($request) => str_contains($request->url(), '/api/v1/users'))->count(), 'one failure is enough to know: no probing of every user');
    }

    public function test_only_vrcx_server_errors_are_tolerated_a_bad_key_still_stops_the_sync(): void
    {
        [$user, $definition] = $this->configuredOwner();
        $this->link($user, $definition, [self::ALICE]);
        $this->freshHttp()->fake(['*/api/v1/users*' => Http::response(['ok' => false, 'error' => 'no'], 401), '*' => Http::response([], 200)]);

        $this->expectException(VrcxException::class);
        $this->expectExceptionMessage('rejected the API key');

        app(VrcxSyncService::class)->sync($user);
    }

    public function test_the_sync_endpoint_hands_the_warnings_to_the_page(): void
    {
        [$user, $definition] = $this->configuredOwner();
        $this->linkMany($user, $definition, 3);
        $this->fakeUsersFailing();

        $this->actingAs($user)->postJson('/settings/vrcx/sync')
            ->assertOk()
            ->assertJsonPath('ran', true)
            ->assertJsonPath('warnings.0', fn ($w) => str_contains($w, 'Internal error.'));
    }
}
