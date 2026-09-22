<?php

namespace Tests\Feature;

use App\Jobs\RecomputeShareLinkAvailability;
use App\Models\ShareLink;
use App\Models\ShareLinkCache;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class ShareLinkRefreshApiTest extends TestCase
{
    use RefreshDatabase;

    private function userWithCalendar(array $attributes = []): User
    {
        return User::factory()->create([
            'calendar_url_ciphertext' => Crypt::encryptString('https://example.com/secret.ics'),
            ...$attributes,
        ]);
    }

    public function test_the_owner_can_force_a_refresh_when_no_cache_exists_yet(): void
    {
        Bus::fake();

        $owner = $this->userWithCalendar();
        $shareLink = ShareLink::factory()->for($owner)->create();

        $response = $this->actingAs($owner)->postJson(route('api.share-links.refresh', $shareLink->highlight_token));

        $response->assertOk()->assertJson(['status' => 'queued']);
        Bus::assertDispatched(RecomputeShareLinkAvailability::class);
    }

    public function test_the_owner_can_force_a_refresh_when_the_cache_is_already_stale(): void
    {
        Bus::fake();

        $owner = $this->userWithCalendar();
        $shareLink = ShareLink::factory()->for($owner)->create();
        ShareLinkCache::create([
            'share_link_id' => $shareLink->id,
            'ciphertext' => 'stale-ciphertext-blob',
            'computed_range_start' => now()->subHours(20),
            'computed_range_end' => now()->addDays(60),
            'encrypted_at' => now()->subMinutes(30),
        ]);

        $response = $this->actingAs($owner)->postJson(route('api.share-links.refresh', $shareLink->highlight_token));

        $response->assertOk()->assertJson(['status' => 'queued']);
        Bus::assertDispatched(RecomputeShareLinkAvailability::class);
    }

    public function test_a_second_refresh_is_throttled_within_the_manual_cooldown(): void
    {
        Bus::fake();

        $owner = $this->userWithCalendar();
        $shareLink = ShareLink::factory()->for($owner)->create();

        $this->actingAs($owner)->postJson(route('api.share-links.refresh', $shareLink->highlight_token))
            ->assertOk()->assertJson(['status' => 'queued']);

        $response = $this->actingAs($owner)->postJson(route('api.share-links.refresh', $shareLink->highlight_token));

        $response->assertStatus(429)->assertJson(['status' => 'throttled']);
        $this->assertGreaterThan(0, $response->json('retry_after_seconds'));
        Bus::assertDispatchedTimes(RecomputeShareLinkAvailability::class, 1);
    }

    /**
     * Regression test for the bug this cooldown key was split out to fix:
     * an ordinary *viewer* loading the page can land a background recompute
     * via ShareLinkAvailabilityController::show() too, which bumps
     * share_link_cache.encrypted_at exactly the same way a manual refresh
     * used to. Before this cooldown moved to its own cache key, that alone
     * throttled the owner's very first manual refresh click, even though
     * they'd never used the button.
     */
    public function test_a_fresh_cache_from_ordinary_viewer_traffic_does_not_throttle_the_owners_first_manual_refresh(): void
    {
        Bus::fake();

        $owner = $this->userWithCalendar();
        $shareLink = ShareLink::factory()->for($owner)->create();
        ShareLinkCache::create([
            'share_link_id' => $shareLink->id,
            'ciphertext' => 'fresh-ciphertext-blob',
            'computed_range_start' => now(),
            'computed_range_end' => now()->addDays(60),
            'encrypted_at' => now()->subMinute(), // fresh, but never granted via a manual refresh
        ]);

        $response = $this->actingAs($owner)->postJson(route('api.share-links.refresh', $shareLink->highlight_token));

        $response->assertOk()->assertJson(['status' => 'queued']);
        Bus::assertDispatched(RecomputeShareLinkAvailability::class);
    }

    public function test_a_stranger_cannot_force_a_refresh(): void
    {
        Bus::fake();

        $owner = $this->userWithCalendar();
        $stranger = User::factory()->create();
        $shareLink = ShareLink::factory()->for($owner)->create();

        $response = $this->actingAs($stranger)->postJson(route('api.share-links.refresh', $shareLink->highlight_token));

        $response->assertForbidden();
        Bus::assertNotDispatched(RecomputeShareLinkAvailability::class);
    }

    public function test_an_unauthenticated_visitor_cannot_force_a_refresh(): void
    {
        Bus::fake();

        $shareLink = ShareLink::factory()->for($this->userWithCalendar())->create();

        $response = $this->postJson(route('api.share-links.refresh', $shareLink->highlight_token));

        $response->assertForbidden();
        Bus::assertNotDispatched(RecomputeShareLinkAvailability::class);
    }

    public function test_a_refresh_is_rejected_when_the_owner_has_no_calendar_url_configured(): void
    {
        Bus::fake();

        $owner = User::factory()->create(['calendar_url_ciphertext' => null]);
        $shareLink = ShareLink::factory()->for($owner)->create();

        $response = $this->actingAs($owner)->postJson(route('api.share-links.refresh', $shareLink->highlight_token));

        $response->assertStatus(422)->assertJson(['status' => 'unconfigured']);
        Bus::assertNotDispatched(RecomputeShareLinkAvailability::class);
    }

    public function test_an_unknown_token_returns_not_found(): void
    {
        $owner = $this->userWithCalendar();

        $this->actingAs($owner)
            ->postJson(route('api.share-links.refresh', 'no-such-token'))
            ->assertNotFound();
    }

    public function test_an_archived_link_returns_not_found(): void
    {
        $owner = $this->userWithCalendar();
        $shareLink = ShareLink::factory()->for($owner)->create(['archived' => true]);

        $this->actingAs($owner)
            ->postJson(route('api.share-links.refresh', $shareLink->highlight_token))
            ->assertNotFound();
    }
}
