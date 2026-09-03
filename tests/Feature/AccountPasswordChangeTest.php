<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * PUT /dashboard/account/password — App\Http\Controllers\Dashboard\
 * AccountController::updatePassword(). The client does the actual crypto
 * (re-deriving the vault key, re-encrypting the key ring) before this
 * request is ever sent — see the controller's own doc comment — so these
 * tests exercise the persistence + confirmation contract, not the crypto
 * itself (that's resources/js/crypto/__tests__).
 */
class AccountPasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'password' => 'correct-current-verifier',
            'passphrase_salt' => base64_encode(random_bytes(16)),
            'key_ring_ciphertext' => 'new-opaque-key-ring-ciphertext',
            'verifier' => 'new-verifier',
        ], $overrides);
    }

    public function test_guests_cannot_change_the_password(): void
    {
        $this->put('/dashboard/account/password', $this->payload())->assertRedirect('/login');
    }

    public function test_missing_current_password_is_rejected(): void
    {
        $user = User::factory()->create();

        $payload = $this->payload();
        unset($payload['password']);

        $this->actingAs($user)
            ->put('/dashboard/account/password', $payload)
            ->assertSessionHasErrors('password');
    }

    public function test_wrong_current_password_is_rejected_and_nothing_changes(): void
    {
        $user = User::factory()->create(['password' => bcrypt('actual-current-verifier')]);
        $saltBefore = $user->passphrase_salt;
        $keyRingBefore = $user->key_ring_ciphertext;

        $this->actingAs($user)
            ->put('/dashboard/account/password', $this->payload(['password' => 'wrong-verifier']))
            ->assertSessionHasErrors('password');

        $user->refresh();
        $this->assertSame($saltBefore, $user->passphrase_salt);
        $this->assertSame($keyRingBefore, $user->key_ring_ciphertext);
    }

    public function test_missing_new_password_fields_are_rejected(): void
    {
        $user = User::factory()->create(['password' => bcrypt('actual-current-verifier')]);

        $payload = $this->payload(['password' => 'actual-current-verifier']);
        unset($payload['key_ring_ciphertext']);

        $this->actingAs($user)
            ->put('/dashboard/account/password', $payload)
            ->assertSessionHasErrors('key_ring_ciphertext');
    }

    public function test_correct_current_password_persists_the_new_salt_key_ring_and_verifier(): void
    {
        $user = User::factory()->create(['password' => bcrypt('actual-current-verifier')]);

        $newSalt = base64_encode(random_bytes(16));

        $response = $this->actingAs($user)->put('/dashboard/account/password', $this->payload([
            'password' => 'actual-current-verifier',
            'passphrase_salt' => $newSalt,
            'key_ring_ciphertext' => 'freshly-rewrapped-key-ring',
            'verifier' => 'brand-new-verifier',
        ]));

        $response->assertRedirect();

        $user->refresh();
        $this->assertSame($newSalt, $user->passphrase_salt);
        $this->assertSame('freshly-rewrapped-key-ring', $user->key_ring_ciphertext);
        $this->assertSame('id', $user->verifier_salt_version);
        $this->assertTrue(Hash::check('brand-new-verifier', $user->password));
    }

    public function test_the_user_can_log_in_with_the_new_verifier_afterward_and_not_the_old_one(): void
    {
        $user = User::factory()->create(['password' => bcrypt('old-verifier'), 'email' => 'pwtest@example.com']);

        $this->actingAs($user)->put('/dashboard/account/password', $this->payload([
            'password' => 'old-verifier',
            'verifier' => 'new-verifier',
        ]));

        $this->post('/logout');

        $this->post('/login', ['identifier' => 'pwtest@example.com', 'password' => 'old-verifier'])
            ->assertSessionHasErrors('identifier');
        $this->assertGuest();

        $this->post('/login', ['identifier' => 'pwtest@example.com', 'password' => 'new-verifier'])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }
}
