<?php

namespace Tests\Feature;

use App\Models\ShareLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegacyShareLinkResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_legacy_token_renders_directly_with_no_redirect(): void
    {
        ShareLink::factory()->for(User::factory())->create([
            'legacy_token' => 'old-legacy-token-abc123',
        ]);

        $response = $this->get('/free/old-legacy-token-abc123');

        $response->assertOk();
    }

    public function test_a_current_share_link_uuid_renders_directly_with_no_redirect(): void
    {
        $shareLink = ShareLink::factory()->for(User::factory())->create();

        $response = $this->get("/free/{$shareLink->id}");

        $response->assertOk();
    }

    public function test_an_unknown_token_returns_404(): void
    {
        $response = $this->get('/free/does-not-exist');

        $response->assertStatus(404);
    }
}
