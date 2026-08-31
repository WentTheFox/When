<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AboutPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_about_page_is_publicly_reachable(): void
    {
        $this->get('/about')->assertOk();
    }

    /**
     * Unlike `/` (which redirects a logged-in visitor to their dashboard —
     * see LandingPageTest), /about stays reachable either way: it's also
     * where the security/data-handling explanation lives now, linked from
     * SiteFooter.vue on every page, dashboard included.
     */
    public function test_the_about_page_is_reachable_even_when_logged_in(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/about')->assertOk();
    }

    public function test_the_old_security_url_redirects_to_about(): void
    {
        $this->get('/security')->assertRedirect('/about');
    }
}
