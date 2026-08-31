<?php

namespace Tests\Feature;

use App\Models\ShareLink;
use App\Models\ShareLinkCache;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ShareLinkAvailabilityApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_pending_and_dispatches_a_recompute_when_nothing_is_cached_yet(): void
    {
        Bus::fake();

        $shareLink = ShareLink::factory()->for(User::factory())->create();

        $response = $this->getJson(route('api.share-links.show', $shareLink));

        $response->assertStatus(202)->assertJson(['status' => 'pending']);
        Bus::assertDispatched(\App\Jobs\RecomputeShareLinkAvailability::class);
    }

    public function test_serves_a_fresh_cached_result_without_triggering_a_recompute(): void
    {
        Bus::fake();

        $shareLink = ShareLink::factory()->for(User::factory())->create();
        ShareLinkCache::create([
            'share_link_id' => $shareLink->id,
            'ciphertext' => 'opaque-ciphertext-blob',
            'computed_range_start' => now(),
            'computed_range_end' => now()->addDays(60),
            'encrypted_at' => now(),
        ]);

        $response = $this->getJson(route('api.share-links.show', $shareLink));

        $response->assertOk()->assertJson([
            'status' => 'ready',
            'ciphertext' => 'opaque-ciphertext-blob',
            'stale' => false,
        ]);
        Bus::assertNotDispatched(\App\Jobs\RecomputeShareLinkAvailability::class);
    }

    public function test_serves_a_stale_cached_result_while_also_triggering_a_recompute(): void
    {
        Bus::fake();

        $shareLink = ShareLink::factory()->for(User::factory())->create();
        ShareLinkCache::create([
            'share_link_id' => $shareLink->id,
            'ciphertext' => 'stale-ciphertext-blob',
            'computed_range_start' => now()->subHours(20),
            'computed_range_end' => now()->addDays(60),
            'encrypted_at' => now()->subMinutes(30), // older than the 15-minute TTL
        ]);

        $response = $this->getJson(route('api.share-links.show', $shareLink));

        $response->assertOk()->assertJson([
            'status' => 'ready',
            'ciphertext' => 'stale-ciphertext-blob',
            'stale' => true,
        ]);
        Bus::assertDispatched(\App\Jobs\RecomputeShareLinkAvailability::class);
    }

    public function test_archived_share_links_are_not_served(): void
    {
        $shareLink = ShareLink::factory()->for(User::factory())->create(['archived' => true]);

        $response = $this->getJson(route('api.share-links.show', $shareLink));

        $response->assertStatus(404);
    }

    public function test_passphrase_protected_links_expose_the_wrapped_key_but_fragment_links_do_not(): void
    {
        $shareLink = ShareLink::factory()->for(User::factory())->create([
            'key_protection' => 'passphrase',
            'wrapped_key' => 'wrapped-key-blob',
            'wrap_salt' => 'salt-blob',
        ]);
        ShareLinkCache::create([
            'share_link_id' => $shareLink->id,
            'ciphertext' => 'ciphertext-blob',
            'computed_range_start' => now(),
            'computed_range_end' => now()->addDays(60),
            'encrypted_at' => now(),
        ]);

        $response = $this->getJson(route('api.share-links.show', $shareLink));

        $response->assertJson([
            'key_protection' => 'passphrase',
            'wrapped_key' => 'wrapped-key-blob',
            'wrap_salt' => 'salt-blob',
        ]);
    }
}
