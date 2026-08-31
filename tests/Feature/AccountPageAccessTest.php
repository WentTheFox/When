<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * /dashboard/account — the page reached by clicking your own avatar/name
 * in the top-right nav. Holds identity (name/email) and security
 * (two-factor) together; TwoFactorController's own setup/disable flow
 * existed but was previously unreachable from anywhere in the UI (only by
 * typing /two-factor directly).
 */
class AccountPageAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_view_the_account_page(): void
    {
        $this->get('/dashboard/account')->assertRedirect('/login');
    }

    public function test_it_reports_two_factor_as_not_enabled_by_default(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/dashboard/account')
            ->assertInertia(fn (Assert $page) => $page->where('twoFactorEnabled', false));
    }

    public function test_it_reports_two_factor_as_enabled_once_confirmed(): void
    {
        $user = User::factory()->create(['two_factor_confirmed_at' => now()]);

        $this->actingAs($user)
            ->get('/dashboard/account')
            ->assertInertia(fn (Assert $page) => $page->where('twoFactorEnabled', true));
    }

    public function test_it_shares_the_current_name_and_email(): void
    {
        $user = User::factory()->create(['name' => 'Test Fox', 'email' => 'fox@example.com']);

        $this->actingAs($user)
            ->get('/dashboard/account')
            ->assertInertia(fn (Assert $page) => $page
                ->where('name', 'Test Fox')
                ->where('email', 'fox@example.com'));
    }

    public function test_owner_can_change_their_name(): void
    {
        $user = User::factory()->create(['name' => 'Old Name']);

        $response = $this->actingAs($user)->patch('/dashboard/account/name', ['name' => 'New Name']);

        $response->assertRedirect();
        $this->assertSame('New Name', $user->fresh()->name);
    }

    public function test_name_must_stay_unique(): void
    {
        User::factory()->create(['name' => 'Taken Fox']);
        $user = User::factory()->create(['name' => 'My Fox']);

        $response = $this->actingAs($user)->patch('/dashboard/account/name', ['name' => 'Taken Fox']);

        $response->assertSessionHasErrors('name');
        $this->assertSame('My Fox', $user->fresh()->name);
    }

    public function test_name_cannot_contain_an_at_symbol(): void
    {
        $user = User::factory()->create(['name' => 'My Fox']);

        $response = $this->actingAs($user)->patch('/dashboard/account/name', ['name' => 'foo@bar']);

        $response->assertSessionHasErrors('name');
        $this->assertSame('My Fox', $user->fresh()->name);
    }

    public function test_owner_can_change_their_email(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->patch('/dashboard/account/email', ['email' => 'new@example.com']);

        $response->assertRedirect();
        $this->assertSame('new@example.com', $user->fresh()->email);
    }

    public function test_owner_can_clear_their_email(): void
    {
        $user = User::factory()->create(['email' => 'has-one@example.com']);

        $response = $this->actingAs($user)->patch('/dashboard/account/email', ['email' => '']);

        $response->assertRedirect();
        $this->assertNull($user->fresh()->email);
    }

    public function test_email_must_stay_unique(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);
        $user = User::factory()->create(['email' => 'mine@example.com']);

        $response = $this->actingAs($user)->patch('/dashboard/account/email', ['email' => 'taken@example.com']);

        $response->assertSessionHasErrors('email');
        $this->assertSame('mine@example.com', $user->fresh()->email);
    }

    public function test_changing_name_or_email_does_not_touch_the_stored_password_hash(): void
    {
        $user = User::factory()->create();
        $passwordHashBefore = $user->password;

        $this->actingAs($user)->patch('/dashboard/account/name', ['name' => 'Brand New Name']);
        $this->actingAs($user)->patch('/dashboard/account/email', ['email' => 'brandnew@example.com']);

        $this->assertSame($passwordHashBefore, $user->fresh()->password);
    }
}
