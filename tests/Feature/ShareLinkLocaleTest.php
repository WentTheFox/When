<?php

namespace Tests\Feature;

use App\Models\ShareLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ShareLinkLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_free_path_renders_the_english_title_and_locale(): void
    {
        $owner = User::factory()->create();
        $owner->setLocalizedField('public_page_title', ['default' => 'English Title', 'hu' => 'Magyar Cim']);
        $shareLink = ShareLink::factory()->for($owner)->create();

        $this->get("/free/{$shareLink->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('pageTitle', 'English Title')
                ->where('locale', 'en'));
    }

    public function test_hu_free_path_renders_the_hungarian_title_and_locale(): void
    {
        $owner = User::factory()->create();
        $owner->setLocalizedField('public_page_title', ['default' => 'English Title', 'hu' => 'Magyar Cim']);
        $shareLink = ShareLink::factory()->for($owner)->create();

        // A stored locale cookie, not a bare request — ShareLinkController
        // now redirects /hu/free away from itself for a browser whose
        // Accept-Language doesn't actually prefer Hungarian (see
        // ShareLinkLocaleDetectionTest), which the test client's own
        // default Accept-Language would otherwise trigger here too.
        $this->withCookie('wtf-locale', 'hu')
            ->get("/hu/free/{$shareLink->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('pageTitle', 'Magyar Cim')
                ->where('locale', 'hu'));
    }

    public function test_hu_free_path_falls_back_to_the_english_title_when_hungarian_is_unset(): void
    {
        $owner = User::factory()->create();
        $owner->setLocalizedField('public_page_title', ['default' => 'English Title']);
        $shareLink = ShareLink::factory()->for($owner)->create();

        $this->withCookie('wtf-locale', 'hu')
            ->get("/hu/free/{$shareLink->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('pageTitle', 'English Title'));
    }

    public function test_hu_free_path_still_resolves_a_legacy_token(): void
    {
        ShareLink::factory()->for(User::factory())->create([
            'legacy_token' => 'old-legacy-token-xyz789',
        ]);

        $this->withCookie('wtf-locale', 'hu')
            ->get('/hu/free/old-legacy-token-xyz789')
            ->assertOk();
    }
}
