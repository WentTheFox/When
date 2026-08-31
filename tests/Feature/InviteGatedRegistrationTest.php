<?php

namespace Tests\Feature;

use App\Models\Invite;
use App\Models\InviteRedemption;
use App\Models\User;
use App\Services\InviteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class InviteGatedRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_fails_without_a_valid_invite_code(): void
    {
        // A user already exists, so the first-user bootstrap exception
        // (tested separately below) doesn't apply here.
        User::factory()->create();

        $response = $this->post('/register', $this->registrationPayload(['invite_code' => 'not-a-real-code']));

        $response->assertSessionHasErrors('invite_code');
        $this->assertGuest();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_the_first_ever_user_can_register_without_an_invite_code(): void
    {
        $this->assertDatabaseCount('users', 0);

        $response = $this->post('/register', $this->registrationPayload(['invite_code' => '']));

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
        $this->assertTrue(User::whereEmail('newfox@example.com')->exists());
    }

    public function test_a_second_user_still_needs_an_invite_even_right_after_the_first(): void
    {
        User::factory()->create();

        $response = $this->post('/register', $this->registrationPayload(['invite_code' => '']));

        $response->assertSessionHasErrors('invite_code');
        $this->assertDatabaseCount('users', 1);
    }

    public function test_valid_invite_code_completes_signup_and_stores_vault_material(): void
    {
        $inviter = User::factory()->create();
        $invite = app(InviteService::class)->issue($inviter);

        $response = $this->post('/register', $this->registrationPayload([
            'invite_code' => $invite->code,
            'passphrase_salt' => 'c2FsdC1iYXNlNjQ=',
            'key_ring_ciphertext' => 'ZW5jcnlwdGVkLWtleS1yaW5n',
        ]));

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();

        $user = User::whereEmail('newfox@example.com')->firstOrFail();
        $this->assertSame('c2FsdC1iYXNlNjQ=', $user->passphrase_salt);
        $this->assertSame('ZW5jcnlwdGVkLWtleS1yaW5n', $user->key_ring_ciphertext);

        $this->assertDatabaseHas('invite_redemptions', [
            'invite_id' => $invite->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_a_single_use_invite_cannot_be_redeemed_twice(): void
    {
        $inviter = User::factory()->create();
        $invite = app(InviteService::class)->issue($inviter, maxUses: 1);

        $this->post('/register', $this->registrationPayload([
            'invite_code' => $invite->code,
            'email' => 'first@example.com',
        ]));
        $this->assertAuthenticated();

        // Fresh, unauthenticated client for the second attempt.
        $this->app['auth']->guard()->logout();
        $this->flushSession();

        $response = $this->post('/register', $this->registrationPayload([
            'invite_code' => $invite->code,
            'email' => 'second@example.com',
        ]));

        $response->assertSessionHasErrors('invite_code');
        $this->assertFalse(User::whereEmail('second@example.com')->exists());
    }

    public function test_an_expired_invite_cannot_be_redeemed(): void
    {
        $inviter = User::factory()->create();
        $invite = app(InviteService::class)->issue($inviter, expiresAt: Carbon::now()->subDay());

        $response = $this->post('/register', $this->registrationPayload(['invite_code' => $invite->code]));

        $response->assertSessionHasErrors('invite_code');
        $this->assertGuest();
    }

    public function test_the_register_page_shows_the_inviters_name_not_a_manual_code_field(): void
    {
        $inviter = User::factory()->create(['name' => 'Inviting Fox']);
        $invite = app(InviteService::class)->issue($inviter);

        $response = $this->get(route('register', ['code' => $invite->code]));

        $response->assertOk();
        $response->assertSee('Inviting Fox');
        $response->assertDontSee('name="invite_code" class="form-control"', false);
    }

    public function test_the_register_page_hides_the_form_entirely_for_an_invalid_invite_link(): void
    {
        User::factory()->create();

        $response = $this->get(route('register', ['code' => 'not-a-real-code']));

        $response->assertOk();
        $response->assertDontSee('id="register-form"', false);
    }

    public function test_viewing_a_share_link_surfaces_a_create_your_own_invite_attributed_to_the_owner(): void
    {
        $owner = User::factory()->create();
        $shareLink = \App\Models\ShareLink::factory()->for($owner)->create();

        $response = $this->get(route('share-links.show', $shareLink));

        $response->assertOk();

        $invite = Invite::where('source_share_link_id', $shareLink->id)->first();
        $this->assertNotNull($invite);
        $this->assertSame($owner->id, $invite->inviter_user_id);
        $response->assertSee($invite->code);
    }

    private function registrationPayload(array $overrides = []): array
    {
        return array_merge([
            'invite_code' => '',
            'name' => 'New Fox',
            'email' => 'newfox@example.com',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
            'passphrase_salt' => 'c2FsdA==',
            'key_ring_ciphertext' => 'Y2lwaGVydGV4dA==',
        ], $overrides);
    }
}
