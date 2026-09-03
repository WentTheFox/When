<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * POST /login/lookup — AuthenticatedSessionController::lookup(). Reachable
 * by both guests (the login page itself) and already-authenticated callers
 * (ConfirmPasswordModal.vue, Account.vue's change-master-password flow, both
 * of which need the account's own id-based verifier salt). It must NOT sit
 * behind the `guest` middleware — that would 302-redirect an authenticated
 * caller to /dashboard instead of returning the {id, saltVersion} JSON those
 * flows expect, which is exactly the bug this test guards against (an
 * authenticated caller silently got an HTML redirect body back, and
 * deriveLoginVerifier() blew up trying to .trim() the missing id).
 */
class LoginLookupTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_can_look_up_an_identifier(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson('/login/lookup', ['identifier' => $user->name]);

        $response->assertOk();
        $response->assertJson(['id' => $user->id]);
    }

    public function test_an_authenticated_user_can_also_look_up_an_identifier(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/login/lookup', ['identifier' => $other->name]);

        $response->assertOk();
        $response->assertJson(['id' => $other->id]);
    }

    public function test_an_authenticated_user_looking_up_their_own_identifier_gets_json_not_a_redirect(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/login/lookup', ['identifier' => $user->name]);

        $response->assertOk();
        $response->assertJson(['id' => $user->id]);
    }
}
