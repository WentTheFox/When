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
