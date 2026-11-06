<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\ConnectionAttributeDefinition;
use App\Models\ConnectionAttributeValue;
use App\Models\ConnectionVrchatProfile;
use App\Models\ShareLink;
use App\Models\ShareLinkCache;
use App\Models\User;
use App\Services\Vrcx\VrcxSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A VRChat id saved on a connection is looked up as part of that save, and the
 * person's display name is copied onto the connection as a highlight token.
 */
class VrcxSaveAndTokensTest extends TestCase
{
    use RefreshDatabase;

    private const ALICE = 'usr_00000000-0000-0000-0000-00000000a11c';

    private const BOB = 'usr_00000000-0000-0000-0000-0000000000b0';

    private User $user;

    private ConnectionAttributeDefinition $vrchatField;

    private ConnectionAttributeDefinition $highlightField;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->vrchatField = $this->field('identifier_list');
        $this->highlightField = $this->field('identifier_list');
        $this->user->forceFill([
            'vrcx_base_url' => 'https://vrcx.example.test', 'vrcx_api_key' => 'k',
            'vrchat_user_id_definition_id' => $this->vrchatField->id,
            'highlight_identifier_definition_id' => $this->highlightField->id,
        ])->save();
    }

    private function field(string $type): ConnectionAttributeDefinition
    {
        return ConnectionAttributeDefinition::create(['id' => (string) Str::uuid(), 'user_id' => $this->user->id, 'label_ciphertext' => 'x', 'type' => $type]);
    }

    private function connection(): Connection
    {
        return Connection::create(['id' => (string) Str::uuid(), 'user_id' => $this->user->id, 'name_ciphertext' => 'opaque']);
    }

    /** @param array<string, string> $names VRChat id => display name VRCX has cached */
    private function fakeVrcx(array $names): Factory
    {
        Http::swap($factory = new Factory);
        $factory->fake([
            '*/api/v1/users*' => Http::response(['ok' => true, 'missing' => [], 'users' => collect($names)->map(fn ($name, $id) => [
                'id' => $id, 'displayName' => $name, 'profilePictureUrl' => 'https://img.test/'.substr($id, -2).'.png', 'avatarThumbnailUrl' => '',
            ])->all()]),
            '*/api/v1/time-together*' => fn ($request) => Http::response(['ok' => true, 'users' => collect($request['ids'])->mapWithKeys(fn ($id) => [$id => ['total' => 1, 'results' => [
                ['userId' => $id, 'start' => '2026-10-01T10:00:00.000Z', 'end' => '2026-10-01T11:00:00.000Z', 'durationMs' => 3600000, 'otherPlayerCount' => 0],
            ]]])->all()]),
        ]);

        return $factory;
    }

    private function saveIds(Connection $connection, array $ids, array $extra = []): TestResponse
    {
        return $this->actingAs($this->user->refresh())->patchJson("/dashboard/connections/{$connection->id}", ['attribute_values' => [
            ['attribute_definition_id' => $this->vrchatField->id, 'identifier_list_value' => $ids], ...$extra,
        ]]);
    }

    private function highlightTokens(Connection $connection): array
    {
        return ConnectionAttributeValue::where('connection_id', $connection->id)->where('attribute_definition_id', $this->highlightField->id)->first()?->identifier_list_value ?? [];
    }

    public function test_saving_a_connection_with_a_vrchat_id_fetches_its_profile_in_the_same_request(): void
    {
        $connection = $this->connection();
        $this->fakeVrcx([self::ALICE => 'Alice']);

        $this->saveIds($connection, [self::ALICE])
            ->assertOk()
            ->assertJsonPath('vrchat_profiles.0.display_name', 'Alice')
            ->assertJsonPath('vrchat_profiles.0.total_ms', 3600000)
            ->assertJsonPath('vrchat_profiles.0.vrchat_user_id', self::ALICE);

        $this->assertSame(1, ConnectionVrchatProfile::where('connection_id', $connection->id)->count());
    }

    public function test_creating_a_connection_with_a_vrchat_id_fetches_it_too(): void
    {
        $this->fakeVrcx([self::ALICE => 'Alice']);

        $this->actingAs($this->user)->postJson('/dashboard/connections', [
            'id' => (string) Str::uuid(), 'name_ciphertext' => 'opaque',
            'attribute_values' => [['attribute_definition_id' => $this->vrchatField->id, 'identifier_list_value' => [self::ALICE]]],
        ])->assertCreated()->assertJsonPath('vrchat_profiles.0.display_name', 'Alice');
    }

    public function test_only_changed_ids_trigger_a_fetch_and_removed_ids_lose_their_cache(): void
    {
        $connection = $this->connection();
        $factory = $this->fakeVrcx([self::ALICE => 'Alice']);
        $this->saveIds($connection, [self::ALICE])->assertOk();

        $factory->fake();
        $this->saveIds($connection, [self::ALICE])->assertOk();
        Http::assertNothingSent();

        $this->fakeVrcx([self::ALICE => 'Alice']);
        $this->saveIds($connection, [])->assertOk()->assertJsonPath('vrchat_profiles', []);
        $this->assertSame(0, ConnectionVrchatProfile::count());
    }

    public function test_a_save_that_does_not_touch_the_values_never_calls_vrcx(): void
    {
        $connection = $this->connection();
        $this->fakeVrcx([]);

        $this->actingAs($this->user)->patchJson("/dashboard/connections/{$connection->id}", ['archived' => true])->assertOk();

        Http::assertNothingSent();
    }

    public function test_a_vrcx_that_is_down_never_fails_the_save(): void
    {
        $connection = $this->connection();
        Http::swap($factory = new Factory);
        $factory->fake(['*' => fn () => throw new ConnectionException('down')]);

        $this->saveIds($connection, [self::ALICE])->assertOk()->assertJsonPath('vrchat_profiles', []);

        $this->assertSame([self::ALICE], ConnectionAttributeValue::where('connection_id', $connection->id)->where('attribute_definition_id', $this->vrchatField->id)->sole()->identifier_list_value);
    }

    public function test_nothing_is_fetched_without_a_configured_server(): void
    {
        $this->user->forceFill(['vrcx_base_url' => null])->save();
        $connection = $this->connection();
        $this->fakeVrcx([self::ALICE => 'Alice']);

        $this->saveIds($connection, [self::ALICE])->assertOk();

        Http::assertNothingSent();
    }

    public function test_the_display_name_is_copied_onto_the_connection_as_a_highlight_token(): void
    {
        $connection = $this->connection();
        $this->fakeVrcx([self::ALICE => 'Alice']);

        $response = $this->saveIds($connection, [self::ALICE])->assertOk();

        $this->assertSame(['Alice'], $this->highlightTokens($connection));
        $this->assertContains('Alice', collect($response->json('attribute_values'))->firstWhere('attribute_definition_id', $this->highlightField->id)['identifier_list_value']);
    }

    public function test_a_rename_adds_the_new_name_and_keeps_the_old_one(): void
    {
        $connection = $this->connection();
        $this->saveIds($connection, [self::ALICE]);
        ConnectionAttributeValue::create(['connection_id' => $connection->id, 'attribute_definition_id' => $this->highlightField->id, 'identifier_list_value' => ['Mom']]);

        $this->fakeVrcx([self::ALICE => 'Alice']);
        app(VrcxSyncService::class)->sync($this->user->refresh());
        $this->fakeVrcx([self::ALICE => 'Alice (new name)']);
        app(VrcxSyncService::class)->sync($this->user->refresh());
        app(VrcxSyncService::class)->sync($this->user->refresh());

        $this->assertSame(['Mom', 'Alice', 'Alice (new name)'], $this->highlightTokens($connection), 'copies are kept, nothing is removed or duplicated');
    }

    public function test_a_token_that_differs_only_in_case_is_not_added_again(): void
    {
        $connection = $this->connection();
        ConnectionAttributeValue::create(['connection_id' => $connection->id, 'attribute_definition_id' => $this->highlightField->id, 'identifier_list_value' => ['alice']]);
        $this->fakeVrcx([self::ALICE => 'ALICE']);

        $this->saveIds($connection, [self::ALICE], [['attribute_definition_id' => $this->highlightField->id, 'identifier_list_value' => ['alice']]])->assertOk();

        $this->assertSame(['alice'], $this->highlightTokens($connection));
    }

    public function test_no_tokens_are_added_without_a_highlight_field(): void
    {
        $this->user->forceFill(['highlight_identifier_definition_id' => null])->save();
        $connection = $this->connection();
        $this->fakeVrcx([self::ALICE => 'Alice']);

        $this->saveIds($connection, [self::ALICE])->assertOk();

        $this->assertSame(0, ConnectionAttributeValue::where('attribute_definition_id', $this->highlightField->id)->count());
    }

    public function test_adding_a_token_drops_the_share_links_cached_result(): void
    {
        $connection = $this->connection();
        $link = ShareLink::factory()->create(['user_id' => $this->user->id, 'connection_id' => $connection->id]);
        ShareLinkCache::create(['share_link_id' => $link->id, 'ciphertext' => 'x', 'computed_range_start' => now(), 'computed_range_end' => now()->addDay(), 'encrypted_at' => now()]);
        $this->fakeVrcx([self::ALICE => 'Alice']);

        $this->saveIds($connection, [self::ALICE])->assertOk();

        $this->assertSame(0, ShareLinkCache::where('share_link_id', $link->id)->count());
    }

    public function test_friend_list_names_become_tokens_even_when_vrcx_has_not_cached_the_user(): void
    {
        $this->user->forceFill(['vrcx_auto_create_friends' => true])->save();
        $connection = $this->connection();
        ConnectionAttributeValue::create(['connection_id' => $connection->id, 'attribute_definition_id' => $this->vrchatField->id, 'identifier_list_value' => [self::BOB]]);

        Http::swap($factory = new Factory);
        $factory->fake([
            '*/api/v1/friends*' => Http::response(['ok' => true, 'total' => 1, 'results' => [['id' => self::BOB, 'displayName' => 'Bobby', 'state' => 'offline', 'status' => '', 'profilePictureUrl' => '', 'avatarThumbnailUrl' => '']]]),
            '*/api/v1/users*' => Http::response(['ok' => true, 'users' => [], 'missing' => [self::BOB]]),
            '*/api/v1/time-together*' => fn ($request) => Http::response(['ok' => true, 'users' => collect($request['ids'])->mapWithKeys(fn ($id) => [$id => ['total' => 0, 'results' => []]])->all()]),
        ]);

        app(VrcxSyncService::class)->sync($this->user->refresh());

        $this->assertSame(['Bobby'], $this->highlightTokens($connection));
    }

    /** A save the way ConnectionCard sends it: the edited highlight list plus the list as the form loaded it. */
    private function saveTokens(Connection $connection, array $submitted, array $base)
    {
        return $this->actingAs($this->user->refresh())->patchJson("/dashboard/connections/{$connection->id}", ['attribute_values' => [
            ['attribute_definition_id' => $this->highlightField->id, 'identifier_list_value' => $submitted, 'identifier_list_base' => $base],
        ]]);
    }

    public function test_saving_from_a_stale_page_keeps_tokens_the_server_added_since_it_loaded(): void
    {
        $connection = $this->connection();
        $this->saveIds($connection, [self::ALICE]);
        ConnectionAttributeValue::create(['connection_id' => $connection->id, 'attribute_definition_id' => $this->highlightField->id, 'identifier_list_value' => ['Mom']]);

        // The page loaded with ["Mom"]. Meanwhile the VRChat name was copied in.
        $this->fakeVrcx([self::ALICE => 'Alice']);
        app(VrcxSyncService::class)->sync($this->user->refresh());
        $this->assertSame(['Mom', 'Alice'], $this->highlightTokens($connection));

        // The owner, still on the old page, adds "Mum" and saves.
        $this->saveTokens($connection, ['Mom', 'Mum'], ['Mom'])->assertOk();

        $this->assertSame(['Mom', 'Mum', 'Alice'], $this->highlightTokens($connection));
    }

    public function test_a_token_the_owner_deleted_is_gone_but_a_newer_one_stays(): void
    {
        $connection = $this->connection();
        ConnectionAttributeValue::create(['connection_id' => $connection->id, 'attribute_definition_id' => $this->highlightField->id, 'identifier_list_value' => ['Old name', 'Newer']]);

        // The page loaded with only "Old name"; the owner deletes it.
        $this->saveTokens($connection, [], ['Old name'])->assertOk();

        $this->assertSame(['Newer'], $this->highlightTokens($connection));
    }

    public function test_a_save_without_a_base_list_replaces_the_list_as_before(): void
    {
        $connection = $this->connection();
        ConnectionAttributeValue::create(['connection_id' => $connection->id, 'attribute_definition_id' => $this->highlightField->id, 'identifier_list_value' => ['A', 'B']]);

        $this->actingAs($this->user)->patchJson("/dashboard/connections/{$connection->id}", ['attribute_values' => [
            ['attribute_definition_id' => $this->highlightField->id, 'identifier_list_value' => ['C']],
        ]])->assertOk();

        $this->assertSame(['C'], $this->highlightTokens($connection));
    }

    public function test_an_empty_merged_list_leaves_no_row(): void
    {
        $connection = $this->connection();
        ConnectionAttributeValue::create(['connection_id' => $connection->id, 'attribute_definition_id' => $this->highlightField->id, 'identifier_list_value' => ['A']]);

        $this->saveTokens($connection, [], ['A'])->assertOk();

        $this->assertSame(0, ConnectionAttributeValue::where('connection_id', $connection->id)->where('attribute_definition_id', $this->highlightField->id)->count());
    }

    public function test_the_vrchat_id_list_is_merged_too(): void
    {
        $connection = $this->connection();
        $this->fakeVrcx([]);
        $this->saveIds($connection, [self::ALICE]);
        // Another tab linked Bob after this page loaded with only Alice.
        ConnectionAttributeValue::where('connection_id', $connection->id)->where('attribute_definition_id', $this->vrchatField->id)->sole()
            ->update(['identifier_list_value' => [self::ALICE, self::BOB]]);

        $this->actingAs($this->user)->patchJson("/dashboard/connections/{$connection->id}", ['attribute_values' => [
            ['attribute_definition_id' => $this->vrchatField->id, 'identifier_list_value' => [self::ALICE], 'identifier_list_base' => [self::ALICE]],
        ]])->assertOk();

        $this->assertSame([self::ALICE, self::BOB], ConnectionAttributeValue::where('connection_id', $connection->id)->where('attribute_definition_id', $this->vrchatField->id)->sole()->identifier_list_value);
    }
}
