<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Stage 8: /free/{token} and /register are unauthenticated-reachable, so
 * IP-keyed throttling (see AppServiceProvider::boot) is the only defense
 * against enumerating share-link tokens or invite codes. This confirms the
 * limiters are actually wired to the routes, not just registered and unused.
 */
class RateLimitingTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        RateLimiter::clear('invite-redemption:127.0.0.1');
        RateLimiter::clear('share-link-view:127.0.0.1');

        parent::tearDown();
    }

    public function test_share_link_viewing_is_rate_limited_per_ip(): void
    {
        for ($i = 0; $i < 120; $i++) {
            $this->get('/free/nonexistent-token');
        }

        $this->get('/free/nonexistent-token')->assertStatus(429);
    }

    public function test_invite_redemption_is_rate_limited_per_ip(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->get('/register');
        }

        $this->get('/register')->assertStatus(429);
    }
}
