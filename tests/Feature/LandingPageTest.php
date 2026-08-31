<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LandingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_shows_a_register_cta_in_the_header_when_there_are_no_users_yet(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page->where('isFirstUser', true));
    }

    public function test_hides_the_register_cta_once_a_user_exists(): void
    {
        User::factory()->create();

        $response = $this->get('/');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page->where('isFirstUser', false));
    }

    public function test_a_logged_in_visitor_is_redirected_from_the_landing_page_to_the_dashboard(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/')->assertRedirect('/dashboard');
    }

    /**
     * isFirstUser comes from the Inertia shared props
     * (HandleInertiaRequests::share), not from anything /login's own
     * controller passes — this is the actual regression this stage of work
     * was fixing: every Inertia page needs the CTA to reflect reality, not
     * just the ones whose controller remembered to compute it.
     */
    public function test_the_header_cta_reflects_first_user_state_on_pages_that_never_pass_it_themselves(): void
    {
        $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('isFirstUser', true));

        User::factory()->create();

        $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('isFirstUser', false));
    }
}
