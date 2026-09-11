<?php

namespace Tests\Feature;

use App\Models\ShareLink;
use App\Models\ShareLinkVisit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_visitor_locales_matching_a_supported_language_are_not_flagged(): void
    {
        $owner = User::factory()->create();
        $shareLink = ShareLink::factory()->for($owner)->create();

        ShareLinkVisit::factory()->for($shareLink)->create(['locale' => 'hu-HU']);
        ShareLinkVisit::factory()->for($shareLink)->create(['locale' => 'en-US']);

        $response = $this->actingAs($owner)->get('/dashboard');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('unsupportedVisitorLocales', []));
    }

    public function test_visitor_locales_with_no_matching_supported_language_are_flagged(): void
    {
        $owner = User::factory()->create();
        $shareLink = ShareLink::factory()->for($owner)->create();

        ShareLinkVisit::factory()->for($shareLink)->count(2)->create(['locale' => 'sq-AL']);
        ShareLinkVisit::factory()->for($shareLink)->create(['locale' => 'en-US']);

        $response = $this->actingAs($owner)->get('/dashboard');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('unsupportedVisitorLocales', [
            ['language' => 'sq', 'count' => 2],
        ]));
    }

    public function test_visitor_locales_from_another_owners_share_links_are_not_counted(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $strangerLink = ShareLink::factory()->for($stranger)->create();

        ShareLinkVisit::factory()->for($strangerLink)->create(['locale' => 'sq-AL']);

        $response = $this->actingAs($owner)->get('/dashboard');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('unsupportedVisitorLocales', []));
    }
}
