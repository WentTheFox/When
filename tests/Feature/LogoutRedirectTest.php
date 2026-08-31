<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogoutRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_logout_returns_to_the_page_the_user_was_just_on(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->withHeader('referer', url('/settings'))
            ->post('/logout');

        $response->assertRedirect('/settings');
        $this->assertGuest();
    }

    public function test_logout_falls_back_to_the_homepage_with_no_referer(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $response->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_logout_falls_back_to_the_homepage_for_a_foreign_referer(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->withHeader('referer', 'https://evil.example/phishing')
            ->post('/logout');

        $response->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_logout_falls_back_to_the_homepage_when_referer_is_the_logout_route_itself(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->withHeader('referer', url('/logout'))
            ->post('/logout');

        $response->assertRedirect('/');
        $this->assertGuest();
    }
}
