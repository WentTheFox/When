<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\ShareLink;
use App\Models\ShareLinkCache;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShareLinkManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_regenerate_token_replaces_the_public_identifier_and_clears_the_cache(): void
    {
        $owner = User::factory()->create();
        $shareLink = ShareLink::factory()->for($owner)->create();
        ShareLinkCache::create([
            'share_link_id' => $shareLink->id,
            'ciphertext' => 'stale-ciphertext-blob',
            'computed_range_start' => now(),
            'computed_range_end' => now()->addDays(60),
            'encrypted_at' => now(),
        ]);

        $response = $this->actingAs($owner)->postJson("/dashboard/share-links/{$shareLink->id}/regenerate-token");

        $response->assertOk();
        $newToken = $response->json('highlight_token');

        $this->assertNotNull($newToken);
        $this->assertTrue(ctype_alnum($newToken));
        $this->assertSame($newToken, $shareLink->fresh()->highlight_token);
        $this->assertNull($shareLink->fresh()->cache);
    }

    public function test_regenerate_token_replaces_an_existing_highlight_token(): void
    {
        $owner = User::factory()->create();
        $shareLink = ShareLink::factory()->for($owner)->create(['highlight_token' => 'the-old-token']);

        $response = $this->actingAs($owner)->postJson("/dashboard/share-links/{$shareLink->id}/regenerate-token");

        $response->assertOk();
        $this->assertNotSame('the-old-token', $response->json('highlight_token'));
        $this->assertNull(ShareLink::where('highlight_token', 'the-old-token')->first());
    }

    public function test_cannot_regenerate_the_token_of_another_owners_share_link(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $shareLink = ShareLink::factory()->for($stranger)->create();

        $this->actingAs($owner)
            ->postJson("/dashboard/share-links/{$shareLink->id}/regenerate-token")
            ->assertNotFound();
    }

    public function test_delete_permanently_removes_the_share_link(): void
    {
        $owner = User::factory()->create();
        $shareLink = ShareLink::factory()->for($owner)->create();

        $this->actingAs($owner)
            ->deleteJson("/dashboard/share-links/{$shareLink->id}")
            ->assertNoContent();

        $this->assertNull(ShareLink::find($shareLink->id));
    }

    public function test_deleting_a_share_link_untethers_but_does_not_delete_its_connection(): void
    {
        $owner = User::factory()->create();
        $shareLink = ShareLink::factory()->for($owner)->create();
        $connection = Connection::create([
            'user_id' => $owner->id,
            'name_ciphertext' => 'opaque',
            'share_link_id' => $shareLink->id,
        ]);

        $this->actingAs($owner)->deleteJson("/dashboard/share-links/{$shareLink->id}")->assertNoContent();

        $this->assertNotNull($connection->fresh());
        $this->assertNull($connection->fresh()->share_link_id);
    }

    public function test_cannot_delete_another_owners_share_link(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $shareLink = ShareLink::factory()->for($stranger)->create();

        $this->actingAs($owner)
            ->deleteJson("/dashboard/share-links/{$shareLink->id}")
            ->assertNotFound();

        $this->assertNotNull(ShareLink::find($shareLink->id));
    }
}
