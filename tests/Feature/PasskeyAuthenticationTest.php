<?php

namespace Tests\Feature;

use App\Models\Passkey;
use App\Models\User;
use App\Services\TwoFactorAuthenticationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Drives the real passkey endpoints against lbuchs/webauthn with a software
 * authenticator (a genuine ES256 key, hand-built CBOR 'none' attestation) so
 * the whole ceremony — challenge round-trip, signature check, counter — is
 * exercised rather than mocked.
 */
class PasskeyAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGIN = 'http://localhost';

    public function test_a_passkey_can_be_registered_after_confirming_the_master_password(): void
    {
        $user = $this->userWithPassword();
        $authenticator = new SoftwareAuthenticator;

        $response = $this->actingAs($user)->post('/dashboard/account/passkeys', $this->registrationPayload($user, $authenticator, 'Laptop'));

        $response->assertRedirect();
        $response->assertSessionHas('status', 'Passkey added.');
        $response->assertSessionHas('recoveryCodes', fn ($codes) => count($codes) === 8);

        $passkey = $user->passkeys()->sole();
        $this->assertSame('Laptop', $passkey->name);
        $this->assertSame($authenticator->credentialIdBase64Url(), $passkey->credential_id);
        // Name is §0.2 ciphertext at rest.
        $this->assertNotSame('Laptop', \DB::table('passkeys')->value('name'));
    }

    public function test_registration_requires_the_correct_master_password(): void
    {
        $user = $this->userWithPassword();

        $payload = $this->registrationPayload($user, new SoftwareAuthenticator, 'Laptop', password: 'wrong');

        $this->actingAs($user)->post('/dashboard/account/passkeys', $payload)->assertSessionHasErrors('password');
        $this->assertSame(0, $user->passkeys()->count());
    }

    public function test_registration_fails_without_a_matching_challenge(): void
    {
        $user = $this->userWithPassword();
        $authenticator = new SoftwareAuthenticator;

        $payload = $authenticator->attest(random_bytes(32), self::ORIGIN) + ['name' => 'Laptop', 'password' => 'password'];

        $this->actingAs($user)->post('/dashboard/account/passkeys/options')->assertOk();
        $this->post('/dashboard/account/passkeys', $payload)->assertSessionHasErrors('passkey');
        $this->assertSame(0, $user->passkeys()->count());
    }

    public function test_a_registration_challenge_cannot_be_reused(): void
    {
        $user = $this->userWithPassword();
        $authenticator = new SoftwareAuthenticator;

        $options = $this->actingAs($user)->postJson('/dashboard/account/passkeys/options')->json();
        $payload = $authenticator->attest($this->challengeFrom($options), self::ORIGIN) + ['name' => 'A', 'password' => 'password'];

        $this->post('/dashboard/account/passkeys', $payload)->assertSessionHasNoErrors();
        $this->post('/dashboard/account/passkeys', $payload)->assertSessionHasErrors('passkey');
        $this->assertSame(1, $user->passkeys()->count());
    }

    public function test_passkey_only_account_is_challenged_and_can_log_in_with_its_passkey(): void
    {
        [$user, $authenticator] = $this->userWithPasskey();

        $this->post('/login', ['identifier' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest();

        $this->get('/two-factor-challenge')->assertInertia(fn (Assert $page) => $page
            ->where('hasPasskeys', true)
            ->where('hasTotp', false));

        $options = $this->postJson('/two-factor-challenge/passkey/options')->assertOk()->json();
        $this->assertSame($authenticator->credentialIdBase64Url(), $options['publicKey']['allowCredentials'][0]['id']);

        $this->post('/two-factor-challenge/passkey', $authenticator->assert($this->challengeFrom($options), self::ORIGIN, 1))
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $passkey = $user->passkeys()->sole();
        $this->assertSame(1, $passkey->sign_count);
        $this->assertNotNull($passkey->last_used_at);
    }

    public function test_totp_and_passkey_can_both_be_configured_and_either_completes_login(): void
    {
        [$user, $authenticator] = $this->userWithPasskey();
        $this->enableTotp($user);

        // Passkey path.
        $this->post('/login', ['identifier' => $user->email, 'password' => 'password']);
        $this->get('/two-factor-challenge')->assertInertia(fn (Assert $page) => $page
            ->where('hasPasskeys', true)
            ->where('hasTotp', true));
        $options = $this->postJson('/two-factor-challenge/passkey/options')->json();
        $this->post('/two-factor-challenge/passkey', $authenticator->assert($this->challengeFrom($options), self::ORIGIN, 1))
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->app['auth']->guard()->logout();

        // TOTP path, same account.
        $this->post('/login', ['identifier' => $user->email, 'password' => 'password']);
        $code = (new Google2FA)->getCurrentOtp($user->fresh()->two_factor_secret);
        $this->post('/two-factor-challenge', ['code' => $code])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_wrong_challenge_or_signature_is_rejected(): void
    {
        [$user, $authenticator] = $this->userWithPasskey();
        $this->post('/login', ['identifier' => $user->email, 'password' => 'password']);

        $options = $this->postJson('/two-factor-challenge/passkey/options')->json();

        // Signed over a different challenge than the one issued.
        $this->post('/two-factor-challenge/passkey', $authenticator->assert(random_bytes(32), self::ORIGIN, 1))
            ->assertSessionHasErrors('passkey');
        $this->assertGuest();

        // A different authenticator's key under the same credential id.
        $impostor = new SoftwareAuthenticator($authenticator->credentialId);
        $options = $this->postJson('/two-factor-challenge/passkey/options')->json();
        $this->post('/two-factor-challenge/passkey', $impostor->assert($this->challengeFrom($options), self::ORIGIN, 1))
            ->assertSessionHasErrors('passkey');
        $this->assertGuest();
    }

    public function test_an_assertion_cannot_be_replayed(): void
    {
        [$user, $authenticator] = $this->userWithPasskey();
        $this->post('/login', ['identifier' => $user->email, 'password' => 'password']);

        $options = $this->postJson('/two-factor-challenge/passkey/options')->json();
        $payload = $authenticator->assert($this->challengeFrom($options), self::ORIGIN, 1);

        $this->post('/two-factor-challenge/passkey', $payload)->assertRedirect(route('dashboard'));
        $this->app['auth']->guard()->logout();

        // Fresh login, then the captured assertion replayed: the challenge
        // is gone and the counter has moved on.
        $this->post('/login', ['identifier' => $user->email, 'password' => 'password']);
        $this->post('/two-factor-challenge/passkey', $payload)->assertSessionHasErrors('passkey');
        $this->assertGuest();
    }

    public function test_passkey_endpoints_do_nothing_without_a_password_step(): void
    {
        $this->postJson('/two-factor-challenge/passkey/options')->assertStatus(422);
        $this->post('/two-factor-challenge/passkey', [
            'id' => 'x', 'client_data_json' => 'x', 'authenticator_data' => 'x', 'signature' => 'x',
        ])->assertRedirect(route('login'));
    }

    public function test_one_users_passkey_cannot_satisfy_anothers_challenge(): void
    {
        [$alice] = $this->userWithPasskey();
        [$bob, $bobKey] = $this->userWithPasskey();

        $this->post('/login', ['identifier' => $alice->email, 'password' => 'password']);
        $options = $this->postJson('/two-factor-challenge/passkey/options')->json();

        $this->post('/two-factor-challenge/passkey', $bobKey->assert($this->challengeFrom($options), self::ORIGIN, 1))
            ->assertSessionHasErrors('passkey');
        $this->assertGuest();
    }

    public function test_removing_a_passkey_needs_the_password_and_is_scoped_to_its_owner(): void
    {
        [$user] = $this->userWithPasskey();
        [$other] = $this->userWithPasskey();
        $passkey = $user->passkeys()->sole();

        $this->actingAs($user)->delete("/dashboard/account/passkeys/{$passkey->id}", ['password' => 'wrong'])
            ->assertSessionHasErrors('password');
        $this->assertSame(1, $user->passkeys()->count());

        $this->delete('/dashboard/account/passkeys/'.$other->passkeys()->sole()->id, ['password' => 'password'])
            ->assertNotFound();

        $this->delete("/dashboard/account/passkeys/{$passkey->id}", ['password' => 'password'])->assertRedirect();
        $this->assertSame(0, $user->passkeys()->count());
        $this->assertNull(Passkey::withTrashed()->find($passkey->id));
    }

    public function test_removing_the_last_second_factor_drops_recovery_codes_but_totp_keeps_them(): void
    {
        [$user] = $this->userWithPasskey();
        $this->assertNotEmpty($user->two_factor_recovery_codes);

        $this->enableTotp($user);
        $this->actingAs($user)->delete('/dashboard/account/passkeys/'.$user->passkeys()->sole()->id, ['password' => 'password']);
        $this->assertNotEmpty($user->fresh()->two_factor_recovery_codes, 'TOTP still enabled — codes stay');

        [$user2] = $this->userWithPasskey();
        $this->assertNotEmpty($user2->two_factor_recovery_codes);
        $this->actingAs($user2)->delete('/dashboard/account/passkeys/'.$user2->passkeys()->sole()->id, ['password' => 'password']);
        $this->assertEmpty($user2->fresh()->two_factor_recovery_codes);
        $this->assertFalse($user2->fresh()->hasTwoFactor());
    }

    public function test_disabling_totp_leaves_passkey_login_and_recovery_codes_intact(): void
    {
        [$user] = $this->userWithPasskey();
        $this->enableTotp($user);
        $codes = $user->fresh()->two_factor_recovery_codes;

        $this->actingAs($user)->delete('/two-factor');

        $user->refresh();
        $this->assertFalse($user->hasTotp());
        $this->assertTrue($user->hasTwoFactor());
        $this->assertSame($codes, $user->two_factor_recovery_codes);
    }

    public function test_recovery_code_works_for_a_passkey_only_account(): void
    {
        [$user] = $this->userWithPasskey();
        // Registering the passkey already generated them.
        $codes = $user->two_factor_recovery_codes;
        $this->assertCount(8, $codes);

        $this->post('/login', ['identifier' => $user->email, 'password' => 'password']);
        $this->post('/two-factor-challenge', ['recovery_code' => $codes[0]])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_totp_code_alone_is_not_accepted_when_only_a_passkey_exists(): void
    {
        [$user] = $this->userWithPasskey();

        $this->post('/login', ['identifier' => $user->email, 'password' => 'password']);
        $this->post('/two-factor-challenge', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    // --- helpers ---------------------------------------------------------

    private function userWithPassword(): User
    {
        return User::factory()->create(['password' => bcrypt('password')]);
    }

    /** @return array{0: User, 1: SoftwareAuthenticator} */
    private function userWithPasskey(): array
    {
        $user = $this->userWithPassword();
        $authenticator = new SoftwareAuthenticator;

        $this->actingAs($user)->post('/dashboard/account/passkeys', $this->registrationPayload($user, $authenticator, 'Key'));
        $this->app['auth']->guard()->logout();
        $this->flushSession();

        return [$user->fresh(), $authenticator];
    }

    private function enableTotp(User $user): void
    {
        $service = app(TwoFactorAuthenticationService::class);
        $service->generateSecret($user);
        $user->refresh();
        $service->confirm($user, (new Google2FA)->getCurrentOtp($user->two_factor_secret));
    }

    private function registrationPayload(User $user, SoftwareAuthenticator $authenticator, string $name, string $password = 'password'): array
    {
        $options = $this->actingAs($user)->postJson('/dashboard/account/passkeys/options')->assertOk()->json();

        return $authenticator->attest($this->challengeFrom($options), self::ORIGIN) + ['name' => $name, 'password' => $password];
    }

    private function challengeFrom(array $options): string
    {
        $b64 = $options['publicKey']['challenge'];

        return base64_decode(strtr($b64, '-_', '+/').str_repeat('=', (4 - strlen($b64) % 4) % 4));
    }
}

/**
 * Just enough of a WebAuthn authenticator for the 'none' attestation format
 * and ES256: real P-256 key, real signatures, hand-encoded CBOR.
 */
class SoftwareAuthenticator
{
    private \OpenSSLAsymmetricKey $key;

    public string $credentialId;

    public function __construct(?string $credentialId = null)
    {
        $this->key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $this->credentialId = $credentialId ?? random_bytes(32);
    }

    public function credentialIdBase64Url(): string
    {
        return self::b64u($this->credentialId);
    }

    /** @return array{client_data_json: string, attestation_object: string, transports: array<int, string>} */
    public function attest(string $challenge, string $origin): array
    {
        $clientData = json_encode(['type' => 'webauthn.create', 'challenge' => self::b64u($challenge), 'origin' => $origin]);
        $ec = openssl_pkey_get_details($this->key)['ec'];

        $cose = $this->cborMap([
            [1, 2], [3, -7], [-1, 1],
            [-2, str_pad($ec['x'], 32, "\0", STR_PAD_LEFT)],
            [-3, str_pad($ec['y'], 32, "\0", STR_PAD_LEFT)],
        ]);

        $authData = hash('sha256', 'localhost', true)
            .chr(0x41) // user present + attested credential data
            .pack('N', 0)
            .str_repeat("\0", 16)
            .pack('n', strlen($this->credentialId)).$this->credentialId
            .$cose;

        $attestationObject = $this->cborHead(5, 3)
            .$this->cborText('fmt').$this->cborText('none')
            .$this->cborText('attStmt').$this->cborHead(5, 0)
            .$this->cborText('authData').$this->cborHead(2, strlen($authData)).$authData;

        return [
            'client_data_json' => self::b64u($clientData),
            'attestation_object' => self::b64u($attestationObject),
            'transports' => ['internal'],
        ];
    }

    /** @return array{id: string, client_data_json: string, authenticator_data: string, signature: string} */
    public function assert(string $challenge, string $origin, int $signCount): array
    {
        $clientData = json_encode(['type' => 'webauthn.get', 'challenge' => self::b64u($challenge), 'origin' => $origin]);
        $authData = hash('sha256', 'localhost', true).chr(0x01).pack('N', $signCount);

        openssl_sign($authData.hash('sha256', $clientData, true), $signature, $this->key, OPENSSL_ALGO_SHA256);

        return [
            'id' => $this->credentialIdBase64Url(),
            'client_data_json' => self::b64u($clientData),
            'authenticator_data' => self::b64u($authData),
            'signature' => self::b64u($signature),
        ];
    }

    private static function b64u(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    private function cborHead(int $major, int $n): string
    {
        return match (true) {
            $n < 24 => chr($major << 5 | $n),
            $n < 256 => chr($major << 5 | 24).chr($n),
            default => chr($major << 5 | 25).pack('n', $n),
        };
    }

    private function cborText(string $text): string
    {
        return $this->cborHead(3, strlen($text)).$text;
    }

    private function cborInt(int $n): string
    {
        return $n >= 0 ? $this->cborHead(0, $n) : $this->cborHead(1, -1 - $n);
    }

    /** @param  array<int, array{0: int, 1: int|string}>  $pairs */
    private function cborMap(array $pairs): string
    {
        $out = $this->cborHead(5, count($pairs));

        foreach ($pairs as [$k, $v]) {
            $out .= $this->cborInt($k).(is_int($v) ? $this->cborInt($v) : $this->cborHead(2, strlen($v)).$v);
        }

        return $out;
    }
}
