<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\ConnectionAttributeDefinition;
use App\Models\ConnectionAttributeValue;
use App\Models\ConnectionSource;
use App\Models\User;
use App\Models\VrchatFriend;
use App\Services\Vrcx\VrcxException;
use App\Services\Vrcx\VrcxSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/** New VRChat friends (VRCX /api/v1/friends) become connections, created by the browser because names are E2EE. */
class VrcxFriendsTest extends TestCase
{
    use RefreshDatabase;

    private const OLD = 'usr_00000000-0000-0000-0000-0000000000a1';

    private const LINKED = 'usr_00000000-0000-0000-0000-0000000000a2';

    private const NEW = 'usr_00000000-0000-0000-0000-0000000000a3';

    private function owner(bool $autoCreate = true): User
    {
        $user = User::factory()->create();
        $definition = ConnectionAttributeDefinition::create([
            'id' => (string) Str::uuid(), 'user_id' => $user->id, 'label_ciphertext' => 'opaque', 'type' => 'identifier_list',
        ]);
        $user->forceFill([
            'vrcx_base_url' => 'https://vrcx.example.test', 'vrcx_api_key' => 'k',
            'vrchat_user_id_definition_id' => $definition->id, 'vrcx_auto_create_friends' => $autoCreate,
        ])->save();

        return $user;
    }

    private function connectionWith(User $user, string $vrchatId): Connection
    {
        $connection = Connection::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'name_ciphertext' => 'opaque']);
        ConnectionAttributeValue::create([
            'connection_id' => $connection->id, 'attribute_definition_id' => $user->vrchat_user_id_definition_id, 'identifier_list_value' => [$vrchatId],
        ]);

        return $connection;
    }

    /** @param list<string> $ids */
    private function fakeFriends(array $ids, int $status = 200): void
    {
        Http::swap($factory = new Factory);
        $factory->fake([
            '*/api/v1/friends*' => Http::response(['ok' => true, 'total' => count($ids), 'results' => array_map(fn ($id) => [
                'id' => $id, 'displayName' => 'Friend '.substr($id, -2), 'state' => 'offline', 'status' => '',
                'profilePictureUrl' => 'https://img.test/'.substr($id, -2).'.png', 'avatarThumbnailUrl' => '',
            ], $ids)], $status),
            '*/api/v1/users*' => Http::response(['ok' => true, 'users' => [], 'missing' => []]),
            '*/api/v1/time-together*' => fn ($request) => Http::response(['ok' => true, 'users' => collect($request['ids'])->mapWithKeys(fn ($id) => [$id => ['total' => 0, 'results' => []]])->all()]),
        ]);
    }

    private function statuses(User $user): array
    {
        return VrchatFriend::where('user_id', $user->id)->get()->mapWithKeys(fn ($f) => [$f->vrchat_user_id => $f->status])->sortKeys()->all();
    }

    public function test_the_first_sync_makes_existing_friends_pending_unless_they_already_have_a_connection(): void
    {
        $user = $this->owner();
        $this->connectionWith($user, self::LINKED);
        $this->fakeFriends([self::OLD, self::LINKED]);

        app(VrcxSyncService::class)->sync($user);

        $this->assertSame([self::OLD => 'pending', self::LINKED => 'created'], $this->statuses($user));
        $this->assertSame(1, app(VrcxSyncService::class)->summary($user)['pending_friends']);
    }

    public function test_a_friend_who_appears_later_is_pending_exactly_once(): void
    {
        $user = $this->owner();
        $service = app(VrcxSyncService::class);
        $this->fakeFriends([self::OLD]);
        $service->sync($user);

        $this->fakeFriends([self::OLD, self::NEW]);
        $service->sync($user->refresh());
        $service->sync($user->refresh());

        $this->assertSame([self::OLD => 'pending', self::NEW => 'pending'], $this->statuses($user));
        $this->assertSame(2, $service->summary($user)['pending_friends']);
        $this->assertSame(['id' => $user->vrchat_user_id_definition_id, 'type' => 'identifier_list'], $service->summary($user)['field']);
    }

    public function test_deleting_the_connection_does_not_make_the_friend_new_again(): void
    {
        $user = $this->owner();
        $service = app(VrcxSyncService::class);
        $this->fakeFriends([]);
        $service->sync($user);

        $this->fakeFriends([self::NEW]);
        $service->sync($user->refresh());
        $connection = $this->connectionWith($user, self::NEW);
        $service->sync($user->refresh());
        $this->assertSame([self::NEW => 'created'], $this->statuses($user), 'a connection made by hand settles it');

        $connection->forceDelete();
        $service->sync($user->refresh());

        $this->assertSame([self::NEW => 'created'], $this->statuses($user));
    }

    public function test_nothing_is_fetched_or_recorded_while_the_feature_is_off(): void
    {
        $user = $this->owner(autoCreate: false);
        $this->connectionWith($user, self::LINKED);
        $this->fakeFriends([self::NEW]);

        app(VrcxSyncService::class)->sync($user);

        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/api/v1/friends'));
        $this->assertSame([], $this->statuses($user));
    }

    public function test_a_key_without_the_friends_scope_gets_a_clear_message(): void
    {
        $user = $this->owner();
        $this->fakeFriends([], 403);

        try {
            app(VrcxSyncService::class)->sync($user);
            $this->fail('expected a VrcxException');
        } catch (VrcxException $e) {
            $this->assertStringContainsString('friends:read', $e->getMessage());
        }
    }

    public function test_the_summary_names_the_highlight_field_the_browser_matches_against(): void
    {
        $user = $this->owner();
        $list = ConnectionAttributeDefinition::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'label_ciphertext' => 'x', 'type' => 'identifier_list']);
        $user->forceFill(['highlight_identifier_definition_id' => $list->id])->save();

        $this->assertSame($list->id, app(VrcxSyncService::class)->summary($user)['highlight_field_id']);
    }

    public function test_pending_friends_stay_listed_across_page_loads_until_the_owner_acts(): void
    {
        $user = $this->owner();
        $this->fakeFriends([self::OLD, self::NEW]);
        app(VrcxSyncService::class)->sync($user);

        // Asking again and again (every refresh) never hands them out or hides them.
        for ($load = 0; $load < 3; $load++) {
            $response = $this->actingAs($user)->getJson('/dashboard/vrchat/friends')->assertOk();
            $this->assertSame([self::OLD, self::NEW], collect($response->json('friends'))->pluck('vrchat_user_id')->sort()->values()->all());
        }

        $friends = collect($this->actingAs($user)->getJson('/dashboard/vrchat/friends')->json('friends'))->keyBy('vrchat_user_id');
        $this->assertSame('Friend a3', $friends[self::NEW]['display_name']);
        $this->assertSame('https://img.test/a3.png', $friends[self::NEW]['picture_url']);

        // Acting on one removes only that one.
        $this->actingAs($user)->postJson('/dashboard/vrchat/friends/complete', ['ids' => [$friends[self::NEW]['id']]])->assertOk()->assertJson(['completed' => 1]);
        $this->assertSame([self::OLD], array_column($this->actingAs($user)->getJson('/dashboard/vrchat/friends')->json('friends'), 'vrchat_user_id'));
        $this->assertSame(1, app(VrcxSyncService::class)->summary($user)['pending_friends']);

        // Dismissing is for good, even across a later sync.
        $this->actingAs($user)->postJson('/dashboard/vrchat/friends/complete', ['ids' => [], 'skipped' => [$friends[self::OLD]['id']]])->assertOk();
        app(VrcxSyncService::class)->sync($user->refresh());
        $this->assertSame([self::OLD => 'dismissed', self::NEW => 'created'], $this->statuses($user));
        $this->actingAs($user)->getJson('/dashboard/vrchat/friends')->assertJsonCount(0, 'friends');
    }

    public function test_the_list_is_sorted_by_name_and_carries_the_chosen_source(): void
    {
        $user = $this->owner();
        $source = ConnectionSource::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'name_ciphertext' => 'opaque']);
        $others = ConnectionSource::create(['id' => (string) Str::uuid(), 'user_id' => User::factory()->create()->id, 'name_ciphertext' => 'opaque']);

        $this->actingAs($user)->patch('/settings/vrcx', ['vrcx_friends_source_id' => $others->id])->assertSessionHasErrors('vrcx_friends_source_id');
        $this->actingAs($user)->patch('/settings/vrcx', ['vrcx_friends_source_id' => $source->id])->assertSessionHasNoErrors();
        $this->assertSame($source->id, $user->refresh()->vrcx_friends_source_id);

        $this->fakeFriends([self::NEW, self::OLD]);
        app(VrcxSyncService::class)->sync($user);

        $response = $this->actingAs($user)->getJson('/dashboard/vrchat/friends')->assertJsonPath('source_id', $source->id);
        $this->assertSame(['Friend a1', 'Friend a3'], array_column($response->json('friends'), 'display_name'));
        $this->actingAs($user)->get('/dashboard/connections')->assertInertia(fn ($page) => $page->where('vrchat.friends_source_id', $source->id));

        $source->forceDelete();
        $this->assertNull($user->refresh()->vrcx_friends_source_id, 'deleting the source just stops the tagging');
        $this->actingAs($user)->patch('/settings/vrcx', ['vrcx_friends_source_id' => null])->assertSessionHasNoErrors();
    }

    public function test_the_list_is_per_owner_and_needs_the_feature_on(): void
    {
        $mine = $this->owner();
        $theirs = $this->owner();
        $row = VrchatFriend::create(['user_id' => $theirs->id, 'vrchat_user_id_hash' => 'h', 'vrchat_user_id' => self::NEW, 'status' => 'pending']);

        $this->actingAs($mine)->getJson('/dashboard/vrchat/friends')->assertJsonCount(0, 'friends');
        $this->actingAs($mine)->postJson('/dashboard/vrchat/friends/complete', ['ids' => [$row->id]])->assertOk()->assertJson(['completed' => 0]);
        $this->assertSame('pending', $row->refresh()->status, "another owner cannot settle someone else's friend");

        $theirs->forceFill(['vrcx_auto_create_friends' => false])->save();
        $this->actingAs($theirs)->getJson('/dashboard/vrchat/friends')->assertJsonCount(0, 'friends');
    }

    public function test_enabling_needs_the_field_and_a_server_that_offers_friends(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['vrcx_base_url' => 'https://vrcx.example.test', 'vrcx_api_key' => 'k'])->save();

        $this->actingAs($user)->patch('/settings/vrcx', ['vrcx_auto_create_friends' => true])
            ->assertSessionHasErrors('vrcx_auto_create_friends');

        $user = $this->owner(autoCreate: false);
        $spec = fn (array $paths) => Http::swap($f = new Factory) || $f->fake(['*/api/v1/openapi.json' => Http::response([
            'openapi' => '3.1.0', 'info' => ['title' => 'VRCX-Headless external API', 'version' => '1'], 'paths' => $paths,
        ])]);
        $base = ['/api/v1/users' => ['post' => []], '/api/v1/time-together' => ['post' => []]];

        $spec($base);
        $this->actingAs($user)->patch('/settings/vrcx', ['vrcx_auto_create_friends' => true])->assertSessionHasErrors('vrcx_auto_create_friends');
        $this->assertFalse($user->refresh()->vrcx_auto_create_friends);

        $spec($base + ['/api/v1/friends' => ['get' => []]]);
        $this->actingAs($user)->patch('/settings/vrcx', ['vrcx_auto_create_friends' => true])->assertSessionHasNoErrors();
        $user->refresh();
        $this->assertTrue($user->vrcx_auto_create_friends);
    }

    public function test_friends_are_encrypted_at_rest(): void
    {
        $user = $this->owner();
        $this->fakeFriends([]);
        app(VrcxSyncService::class)->sync($user);
        $this->fakeFriends([self::NEW]);
        app(VrcxSyncService::class)->sync($user->refresh());

        $dump = json_encode(DB::table('vrchat_friends')->get());
        foreach ([self::NEW, 'Friend a3', 'img.test'] as $plain) {
            $this->assertStringNotContainsString($plain, $dump);
        }
    }
}
