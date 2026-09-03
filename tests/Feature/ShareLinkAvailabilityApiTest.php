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

class ShareLinkAvailabilityApiTest extends TestCase
{
    use RefreshDatabase;

    /** A calendar URL must be configured for the pending/ready/stale flows below — see the dedicated "unconfigured" tests for the no-calendar-URL path. */
    private function userWithCalendar(array $attributes = []): User
    {
        return User::factory()->create([
            'calendar_url_ciphertext' => Crypt::encryptString('https://example.com/secret.ics'),
            ...$attributes,
        ]);
    }

    public function test_returns_pending_and_dispatches_a_recompute_when_nothing_is_cached_yet(): void
    {
        Bus::fake();

        $shareLink = ShareLink::factory()->for($this->userWithCalendar())->create();

        $response = $this->getJson(route('api.share-links.show', $shareLink->highlight_token));

        $response->assertStatus(202)->assertJson(['status' => 'pending']);
        Bus::assertDispatched(RecomputeShareLinkAvailability::class);
    }

    /**
     * The frontend polls this endpoint every ~2 seconds while a result is
     * pending — without a debounce, every one of those hits would attempt
     * another dispatch for as long as the first fetch+compute is in
     * flight. The job itself is ShouldBeUnique, so those never actually
     * ran twice, but each attempt still cost a lock-acquisition query;
     * this asserts only the first poll in the debounce window dispatches
     * at all.
     */
    public function test_repeated_polling_while_pending_only_dispatches_once(): void
    {
        Bus::fake();

        $shareLink = ShareLink::factory()->for($this->userWithCalendar())->create();

        $this->getJson(route('api.share-links.show', $shareLink->highlight_token))->assertStatus(202);
        $this->getJson(route('api.share-links.show', $shareLink->highlight_token))->assertStatus(202);
        $this->getJson(route('api.share-links.show', $shareLink->highlight_token))->assertStatus(202);

        Bus::assertDispatchedTimes(RecomputeShareLinkAvailability::class, 1);
    }

    public function test_serves_a_fresh_cached_result_without_triggering_a_recompute(): void
    {
        Bus::fake();

        $shareLink = ShareLink::factory()->for($this->userWithCalendar())->create();
        ShareLinkCache::create([
            'share_link_id' => $shareLink->id,
            'ciphertext' => 'opaque-ciphertext-blob',
            'computed_range_start' => now(),
            'computed_range_end' => now()->addDays(60),
            'encrypted_at' => now(),
        ]);

        $response = $this->getJson(route('api.share-links.show', $shareLink->highlight_token));

        $response->assertOk()->assertJson([
            'status' => 'ready',
            'ciphertext' => 'opaque-ciphertext-blob',
            'stale' => false,
        ]);
        Bus::assertNotDispatched(RecomputeShareLinkAvailability::class);
    }

    public function test_serves_a_stale_cached_result_while_also_triggering_a_recompute(): void
    {
        Bus::fake();

        $shareLink = ShareLink::factory()->for($this->userWithCalendar())->create();
        ShareLinkCache::create([
            'share_link_id' => $shareLink->id,
            'ciphertext' => 'stale-ciphertext-blob',
            'computed_range_start' => now()->subHours(20),
            'computed_range_end' => now()->addDays(60),
            'encrypted_at' => now()->subMinutes(30), // older than the 15-minute TTL
        ]);

        $response = $this->getJson(route('api.share-links.show', $shareLink->highlight_token));

        $response->assertOk()->assertJson([
            'status' => 'ready',
            'ciphertext' => 'stale-ciphertext-blob',
            'stale' => true,
        ]);
        Bus::assertDispatched(RecomputeShareLinkAvailability::class);
    }

    public function test_resolves_by_an_explicit_highlight_token(): void
    {
        $shareLink = ShareLink::factory()->for($this->userWithCalendar())->create(['highlight_token' => 'highlight-abc-123']);
        ShareLinkCache::create([
            'share_link_id' => $shareLink->id,
            'ciphertext' => 'highlight-ciphertext-blob',
            'computed_range_start' => now(),
            'computed_range_end' => now()->addDays(60),
            'encrypted_at' => now(),
        ]);

        $response = $this->getJson('/api/share/highlight-abc-123');

        $response->assertOk()->assertJson([
            'status' => 'ready',
            'ciphertext' => 'highlight-ciphertext-blob',
        ]);
    }

    public function test_archived_share_links_return_401_as_the_link_expired_signal(): void
    {
        $shareLink = ShareLink::factory()->for($this->userWithCalendar())->create(['archived' => true]);

        $response = $this->getJson(route('api.share-links.show', $shareLink->highlight_token));

        // 401, not 404 — the link *was* valid, this is "expired," not "never existed."
        $response->assertStatus(401);
    }

    public function test_response_includes_the_owners_timezone(): void
    {
        $user = $this->userWithCalendar(['timezone' => 'Europe/Budapest']);
        $shareLink = ShareLink::factory()->for($user)->create();
        ShareLinkCache::create([
            'share_link_id' => $shareLink->id,
            'ciphertext' => 'ciphertext-blob',
            'computed_range_start' => now(),
            'computed_range_end' => now()->addDays(60),
            'encrypted_at' => now(),
        ]);

        $response = $this->getJson(route('api.share-links.show', $shareLink->highlight_token));

        $response->assertJson(['timezone' => 'Europe/Budapest']);
    }

    /**
     * The bug this guards against: an owner with no calendar URL set was
     * silently stuck on "pending" forever — a recompute got dispatched on
     * every poll, ran, found nothing to fetch, and no-opped, over and
     * over, with no way for the viewer to tell that apart from "still
     * fetching for the first time."
     */
    public function test_returns_unconfigured_and_never_dispatches_a_recompute_when_no_calendar_url_is_set(): void
    {
        Bus::fake();

        $shareLink = ShareLink::factory()->for(User::factory()->create(['calendar_url_ciphertext' => null]))->create();

        $response = $this->getJson(route('api.share-links.show', $shareLink->highlight_token));

        $response->assertOk()->assertJson(['status' => 'unconfigured']);
        Bus::assertNotDispatched(RecomputeShareLinkAvailability::class);
    }

    public function test_unconfigured_status_still_wins_even_with_a_stale_cache_present(): void
    {
        Bus::fake();

        $shareLink = ShareLink::factory()->for(User::factory()->create(['calendar_url_ciphertext' => null]))->create();
        ShareLinkCache::create([
            'share_link_id' => $shareLink->id,
            'ciphertext' => 'stale-ciphertext-blob',
            'computed_range_start' => now()->subHours(20),
            'computed_range_end' => now()->addDays(60),
            'encrypted_at' => now()->subMinutes(30),
        ]);

        $response = $this->getJson(route('api.share-links.show', $shareLink->highlight_token));

        $response->assertOk()->assertJson(['status' => 'unconfigured']);
        Bus::assertNotDispatched(RecomputeShareLinkAvailability::class);
    }
}
