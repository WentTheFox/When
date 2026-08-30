<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TwoFactorAuthenticationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_succeeds_directly_when_two_factor_is_not_enabled(): void
    {
        $user = User::factory()->create(['password' => bcrypt('password')]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_requires_a_totp_challenge_once_two_factor_is_confirmed(): void
    {
        $user = User::factory()->create(['password' => bcrypt('password')]);
        app(TwoFactorAuthenticationService::class)->generateSecret($user);
        $user->refresh();
        $this->confirmTwoFactor($user);
        $this->app['auth']->guard()->logout();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest();
    }

    public function test_correct_totp_code_completes_login(): void
    {
        $user = User::factory()->create(['password' => bcrypt('password')]);
        app(TwoFactorAuthenticationService::class)->generateSecret($user);
        $user->refresh();
        $this->confirmTwoFactor($user);

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $code = (new Google2FA())->getCurrentOtp($user->two_factor_secret);
        $response = $this->post('/two-factor-challenge', ['code' => $code]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_totp_code_fails_the_challenge(): void
    {
        $user = User::factory()->create(['password' => bcrypt('password')]);
        app(TwoFactorAuthenticationService::class)->generateSecret($user);
        $user->refresh();
        $this->confirmTwoFactor($user);

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $response = $this->post('/two-factor-challenge', ['code' => '000000']);

        $response->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_a_recovery_code_can_be_used_once_in_place_of_a_totp_code(): void
    {
        $user = User::factory()->create(['password' => bcrypt('password')]);
        app(TwoFactorAuthenticationService::class)->generateSecret($user);
        $user->refresh();
        $recoveryCodes = $this->confirmTwoFactor($user);
        $user->refresh();

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $response = $this->post('/two-factor-challenge', ['recovery_code' => $recoveryCodes[0]]);
        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->app['auth']->guard()->logout();

        // Same recovery code cannot be reused.
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $response = $this->post('/two-factor-challenge', ['recovery_code' => $recoveryCodes[0]]);
        $response->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    private function confirmTwoFactor(User $user): array
    {
        $service = app(TwoFactorAuthenticationService::class);
        $code = (new Google2FA())->getCurrentOtp($user->two_factor_secret);

        return $service->confirm($user, $code);
    }
}
