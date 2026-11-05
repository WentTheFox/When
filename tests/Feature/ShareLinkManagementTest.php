<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\ShareLink;
use App\Models\ShareLinkCache;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ShareLinkManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_enabling_sharing_creates_one_link_with_a_token_for_the_connection(): void
    {
        $owner = User::factory()->create();
        $connection = Connection::factory()->for($owner)->create();

        $response = $this->actingAs($owner)->postJson("/dashboard/connections/{$connection->id}/share-link");

        $response->assertCreated();
        $this->assertTrue(ctype_alnum($response->json('highlight_token')));
        $this->assertSame($connection->id, ShareLink::findOrFail($response->json('id'))->connection_id);
    }

    public function test_enabling_sharing_twice_returns_the_same_link(): void
    {
        $owner = User::factory()->create();
        $connection = Connection::factory()->for($owner)->create();

        $first = $this->actingAs($owner)->postJson("/dashboard/connections/{$connection->id}/share-link");
        $second = $this->actingAs($owner)->postJson("/dashboard/connections/{$connection->id}/share-link");

        $second->assertOk();
        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertSame(1, ShareLink::where('connection_id', $connection->id)->count());
    }

    public function test_cannot_enable_sharing_on_another_owners_connection(): void
    {
        $owner = User::factory()->create();
        $connection = Connection::factory()->for(User::factory()->create())->create();

        $this->actingAs($owner)
            ->postJson("/dashboard/connections/{$connection->id}/share-link")
            ->assertNotFound();
    }

    public function test_regenerate_token_replaces_the_public_identifier_and_clears_the_cache(): void
    {
        $owner = User::factory()->create();
        $shareLink = ShareLink::factory()->for($owner)->create(['highlight_token' => 'the-old-token']);
        ShareLinkCache::create([
            'share_link_id' => $shareLink->id,
            'ciphertext' => 'stale-ciphertext-blob',
            'computed_range_start' => now(),
            'computed_range_end' => now()->addDays(60),
            'encrypted_at' => now(),
        ]);

        $response = $this->actingAs($owner)
            ->postJson("/dashboard/connections/{$shareLink->connection_id}/share-link/regenerate-token");

        $response->assertOk();
        $newToken = $response->json('highlight_token');

        $this->assertNotSame('the-old-token', $newToken);
        $this->assertTrue(ctype_alnum($newToken));
        $this->assertSame($newToken, $shareLink->fresh()->highlight_token);
        $this->assertNull(ShareLink::where('highlight_token', 'the-old-token')->first());
        $this->assertNull($shareLink->fresh()->cache);
    }

    public function test_cannot_regenerate_the_token_of_another_owners_share_link(): void
    {
        $owner = User::factory()->create();
        $shareLink = ShareLink::factory()->for(User::factory()->create())->create();

        $this->actingAs($owner)
            ->postJson("/dashboard/connections/{$shareLink->connection_id}/share-link/regenerate-token")
            ->assertNotFound();
    }

    public function test_disabling_sharing_deletes_the_link_but_not_the_connection(): void
    {
        $owner = User::factory()->create();
        $shareLink = ShareLink::factory()->for($owner)->create();

        $this->actingAs($owner)
            ->deleteJson("/dashboard/connections/{$shareLink->connection_id}/share-link")
            ->assertNoContent();

        $this->assertNull(ShareLink::find($shareLink->id));
        $this->assertNotNull(Connection::find($shareLink->connection_id));
    }

    public function test_cannot_disable_another_owners_share_link(): void
    {
        $owner = User::factory()->create();
        $shareLink = ShareLink::factory()->for(User::factory()->create())->create();

        $this->actingAs($owner)
            ->deleteJson("/dashboard/connections/{$shareLink->connection_id}/share-link")
            ->assertNotFound();

        $this->assertNotNull(ShareLink::find($shareLink->id));
    }

    public function test_deleting_a_connection_deletes_its_share_link(): void
    {
        $owner = User::factory()->create();
        $shareLink = ShareLink::factory()->for($owner)->create();

        $this->actingAs($owner)
            ->deleteJson("/dashboard/connections/{$shareLink->connection_id}")
            ->assertOk();

        $this->assertNull(ShareLink::find($shareLink->id));
    }

    /** A link has no archive flag of its own: it is archived exactly when its connection is. */
    public function test_a_link_is_archived_exactly_when_its_connection_is(): void
    {
        $owner = User::factory()->create();
        $shareLink = ShareLink::factory()->for($owner)->create();
        $url = "/dashboard/connections/{$shareLink->connection_id}";

        $this->assertFalse($shareLink->fresh()->isArchived());
        $this->assertSame(1, ShareLink::active()->count());

        $this->actingAs($owner)->patchJson($url, ['archived' => true])->assertOk();
        $this->assertTrue($shareLink->fresh()->isArchived());
        $this->assertSame(0, ShareLink::active()->count());

        $this->actingAs($owner)->patchJson($url, ['archived' => false])->assertOk();
        $this->assertFalse($shareLink->fresh()->isArchived());
        $this->assertSame(1, ShareLink::active()->count());
    }

    public function test_archiving_the_connection_does_not_write_to_the_links_own_column(): void
    {
        $owner = User::factory()->create();
        $shareLink = ShareLink::factory()->for($owner)->create();

        $this->actingAs($owner)
            ->patchJson("/dashboard/connections/{$shareLink->connection_id}", ['archived' => true])
            ->assertOk();

        // Nothing is mirrored onto the link; its state is derived.
        $this->assertFalse((bool) DB::table('share_links')->where('id', $shareLink->id)->value('archived'));
    }

    public function test_the_link_payloads_carry_no_archived_flag(): void
    {
        $owner = User::factory()->create();
        $connection = Connection::factory()->for($owner)->create();

        $created = $this->actingAs($owner)->postJson("/dashboard/connections/{$connection->id}/share-link")->assertCreated();
        $this->assertArrayNotHasKey('archived', $created->json());

        $this->actingAs($owner)->get('/dashboard/connections')->assertInertia(
            fn ($page) => $page->has('connections.0.share_link', fn ($link) => $link->missing('archived')->etc()),
        );
    }

    public function test_one_connection_cannot_have_two_share_links(): void
    {
        $owner = User::factory()->create();
        $shareLink = ShareLink::factory()->for($owner)->create();

        $this->expectException(QueryException::class);
        ShareLink::factory()->for($owner)->create(['connection_id' => $shareLink->connection_id]);
    }
}
