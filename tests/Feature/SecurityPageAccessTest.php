<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * /dashboard/security — the entry point the dashboard nav links to, since
 * TwoFactorController's own setup/disable flow existed but was previously
 * unreachable from anywhere in the UI (only by typing /two-factor directly).
 */
class SecurityPageAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_view_the_security_page(): void
    {
        $this->get('/dashboard/security')->assertRedirect('/login');
    }

    public function test_it_reports_two_factor_as_not_enabled_by_default(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/dashboard/security')
            ->assertInertia(fn (Assert $page) => $page->where('twoFactorEnabled', false));
    }

    public function test_it_reports_two_factor_as_enabled_once_confirmed(): void
    {
        $user = User::factory()->create(['two_factor_confirmed_at' => now()]);

        $this->actingAs($user)
            ->get('/dashboard/security')
            ->assertInertia(fn (Assert $page) => $page->where('twoFactorEnabled', true));
    }
}
