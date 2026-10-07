<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TwoFactorAuthenticationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * The one-time move off the legacy email-salted login verifier happens inside
 * the login POST itself (AuthenticatedSessionController::store), not in a
 * follow-up request — with 2FA the follow-up used to be unauthenticated and
 * silently failed, stranding the account on the legacy scheme.
 */
class LegacyVerifierMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_legacy_account_is_migrated_by_a_successful_login(): void
    {
        $user = $this->legacyUser();

        $this->post('/login', [
            'identifier' => $user->email,
            'password' => 'legacy-verifier',
            'migrated_verifier' => 'id-verifier',
        ])->assertRedirect(route('dashboard'));

        $user->refresh();
        $this->assertSame('id', $user->verifier_salt_version);
        $this->assertTrue(Hash::check('id-verifier', $user->password));
        $this->assertFalse(Hash::check('legacy-verifier', $user->password));
    }

    public function test_a_legacy_account_with_two_factor_is_migrated_at_the_password_step(): void
    {
        $user = $this->legacyUser();
        $service = app(TwoFactorAuthenticationService::class);
        $service->generateSecret($user);
        $user->refresh();
        $service->confirm($user, (new Google2FA)->getCurrentOtp($user->two_factor_secret));

        $this->post('/login', [
            'identifier' => $user->email,
            'password' => 'legacy-verifier',
            'migrated_verifier' => 'id-verifier',
        ])->assertRedirect(route('two-factor.challenge'));

        $this->assertGuest();
        $user->refresh();
        $this->assertSame('id', $user->verifier_salt_version);
        $this->assertTrue(Hash::check('id-verifier', $user->password));

        // And the account can still finish logging in afterwards.
        $this->post('/two-factor-challenge', ['code' => (new Google2FA)->getCurrentOtp($user->two_factor_secret)])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_nothing_is_migrated_when_the_legacy_password_is_wrong(): void
    {
        $user = $this->legacyUser();

        $this->post('/login', [
            'identifier' => $user->email,
            'password' => 'wrong',
            'migrated_verifier' => 'attacker-chosen',
        ])->assertSessionHasErrors('identifier');

        $user->refresh();
        $this->assertSame('email', $user->verifier_salt_version);
        $this->assertTrue(Hash::check('legacy-verifier', $user->password));
    }

    public function test_an_already_migrated_account_ignores_a_supplied_replacement(): void
    {
        $user = User::factory()->create(['password' => bcrypt('id-verifier')]);
        $user->forceFill(['verifier_salt_version' => 'id'])->save();

        $this->post('/login', [
            'identifier' => $user->email,
            'password' => 'id-verifier',
            'migrated_verifier' => 'something-else',
        ])->assertRedirect(route('dashboard'));

        $this->assertTrue(Hash::check('id-verifier', $user->fresh()->password));
    }

    private function legacyUser(): User
    {
        $user = User::factory()->create(['password' => bcrypt('legacy-verifier')]);
        $user->forceFill(['verifier_salt_version' => 'email'])->save();

        return $user;
    }
}
