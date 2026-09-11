<?php

namespace Tests\Feature;

use App\Models\ShareLink;
use App\Models\ShareLinkVisit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShareLinkVisitTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_visit_is_recorded_with_the_posted_timezone(): void
    {
        $shareLink = ShareLink::factory()->for(User::factory())->create();

        $response = $this->postJson(route('api.share-links.visits.store', $shareLink->highlight_token), [
            'timezone' => 'Europe/Budapest',
        ]);

        $response->assertCreated();
        $this->assertSame(1, $shareLink->visits()->count());
        $this->assertSame('Europe/Budapest', $shareLink->visits()->first()->timezone);
    }

    public function test_a_visit_is_recorded_with_the_posted_locale(): void
    {
        $shareLink = ShareLink::factory()->for(User::factory())->create();

        $this->postJson(route('api.share-links.visits.store', $shareLink->highlight_token), [
            'timezone' => 'Europe/Budapest',
            'locale' => 'hu-HU',
        ])->assertCreated();

        $this->assertSame('hu-HU', $shareLink->visits()->first()->locale);
    }

    public function test_a_visit_without_a_locale_stores_null(): void
    {
        $shareLink = ShareLink::factory()->for(User::factory())->create();

        $this->postJson(route('api.share-links.visits.store', $shareLink->highlight_token), [
            'timezone' => 'Europe/Budapest',
        ])->assertCreated();

        $this->assertNull($shareLink->visits()->first()->locale);
    }

    public function test_the_owner_viewing_their_own_link_is_not_tracked_but_gets_visitor_timezone_tallies(): void
    {
        $owner = User::factory()->create();
        $shareLink = ShareLink::factory()->for($owner)->create();

        ShareLinkVisit::factory()->for($shareLink)->count(3)->create(['timezone' => 'Europe/Budapest']);
        ShareLinkVisit::factory()->for($shareLink)->count(1)->create(['timezone' => 'America/New_York']);

        $response = $this->actingAs($owner)->postJson(route('api.share-links.visits.store', $shareLink->highlight_token), [
            'timezone' => 'Asia/Tokyo',
        ]);

        $response->assertAccepted();
        $this->assertSame(4, $shareLink->visits()->count());

        $tallies = $response->json('visitor_timezones');
        $this->assertSame(['timezone' => 'Europe/Budapest', 'count' => 3], $tallies[0]);
        $this->assertSame(['timezone' => 'America/New_York', 'count' => 1], $tallies[1]);
    }

    public function test_the_owner_viewing_their_own_link_gets_visitor_locale_tallies(): void
    {
        $owner = User::factory()->create();
        $shareLink = ShareLink::factory()->for($owner)->create();

        ShareLinkVisit::factory()->for($shareLink)->count(2)->create(['locale' => 'hu-HU']);
        ShareLinkVisit::factory()->for($shareLink)->count(1)->create(['locale' => 'en-US']);
        ShareLinkVisit::factory()->for($shareLink)->count(1)->create(['locale' => null]);

        $response = $this->actingAs($owner)->postJson(route('api.share-links.visits.store', $shareLink->highlight_token), [
            'timezone' => 'Asia/Tokyo',
        ]);

        $response->assertAccepted();
        $tallies = $response->json('visitor_locales');
        $this->assertSame(['locale' => 'hu-HU', 'count' => 2], $tallies[0]);
        $this->assertSame(['locale' => 'en-US', 'count' => 1], $tallies[1]);
        $this->assertCount(2, $tallies); // the null-locale visit contributes no entry
    }

    public function test_the_owner_viewing_a_link_with_no_prior_visits_gets_an_empty_tally(): void
    {
        $owner = User::factory()->create();
        $shareLink = ShareLink::factory()->for($owner)->create();

        $response = $this->actingAs($owner)->postJson(route('api.share-links.visits.store', $shareLink->highlight_token), [
            'timezone' => 'Asia/Tokyo',
        ]);

        $response->assertAccepted();
        $this->assertSame([], $response->json('visitor_timezones'));
        $this->assertSame(0, $shareLink->visits()->count());
    }

    public function test_an_invalid_timezone_is_rejected(): void
    {
        $shareLink = ShareLink::factory()->for(User::factory())->create();

        $this->postJson(route('api.share-links.visits.store', $shareLink->highlight_token), [
            'timezone' => 'Not/A_Timezone',
        ])->assertUnprocessable();

        $this->assertSame(0, $shareLink->visits()->count());
    }

    public function test_an_unknown_token_returns_not_found(): void
    {
        $this->postJson(route('api.share-links.visits.store', 'no-such-token'), [
            'timezone' => 'UTC',
        ])->assertNotFound();
    }

    public function test_an_archived_link_returns_not_found(): void
    {
        $shareLink = ShareLink::factory()->for(User::factory())->create(['archived' => true]);

        $this->postJson(route('api.share-links.visits.store', $shareLink->highlight_token), [
            'timezone' => 'UTC',
        ])->assertNotFound();

        $this->assertSame(0, $shareLink->visits()->count());
    }

    public function test_the_owner_can_list_a_links_visit_history_paginated(): void
    {
        $owner = User::factory()->create();
        $shareLink = ShareLink::factory()->for($owner)->create();

        ShareLinkVisit::factory()->for($shareLink)->count(15)->create();

        $response = $this->actingAs($owner)->getJson("/dashboard/share-links/{$shareLink->id}/visits");

        $response->assertOk();
        $this->assertCount(10, $response->json('data'));
        $this->assertSame(15, $response->json('total'));
        $this->assertSame(2, $response->json('last_page'));
    }

    public function test_cannot_list_visits_for_another_owners_share_link(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $shareLink = ShareLink::factory()->for($stranger)->create();

        $this->actingAs($owner)
            ->getJson("/dashboard/share-links/{$shareLink->id}/visits")
            ->assertNotFound();
    }
}
