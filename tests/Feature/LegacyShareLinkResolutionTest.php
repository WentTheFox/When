<?php

namespace Tests\Feature;

use App\Models\ShareLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LegacyShareLinkResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_share_link_resolves_directly_by_its_highlight_token(): void
    {
        ShareLink::factory()->for(User::factory())->create([
            'highlight_token' => 'old-highlight-token-abc123',
        ]);

        $this->get('/free/old-highlight-token-abc123')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('linkFound', true));
    }

    /**
     * A share link's raw UUID id is never a valid public token — only its
     * own highlight_token resolves it (see ShareLinkController's doc
     * comment) — so this renders the same "link expired" state (401, same
     * signal as ShareLinkAvailabilityController's own archived-link
     * response) as any other unmatched token, not a redirect or a 404.
     */
    public function test_a_share_links_raw_id_does_not_resolve_it(): void
    {
        $shareLink = ShareLink::factory()->for(User::factory())->create();

        $this->get("/free/{$shareLink->id}")
            ->assertStatus(401)
            ->assertInertia(fn (Assert $page) => $page->where('linkFound', false));
    }

    /**
     * No hard 404 for an unmatched token — a branded "link expired" page
     * instead, same as a bare /free visit (see
     * ShareLinkController::render()'s doc comment).
     */
    public function test_an_unknown_token_renders_the_link_expired_state(): void
    {
        $this->get('/free/does-not-exist')
            ->assertStatus(401)
            ->assertInertia(fn (Assert $page) => $page->where('linkFound', false));
    }

    public function test_a_bare_free_visit_with_no_token_renders_the_link_expired_state(): void
    {
        $this->get('/free')
            ->assertStatus(401)
            ->assertInertia(fn (Assert $page) => $page
                ->where('linkFound', false)
                ->where('token', null));
    }
}
