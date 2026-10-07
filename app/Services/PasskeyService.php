<?php

namespace App\Services;

use App\Models\Passkey;
use App\Models\User;
use Illuminate\Http\Request;
use lbuchs\WebAuthn\Binary\ByteBuffer;
use lbuchs\WebAuthn\WebAuthn;
use lbuchs\WebAuthn\WebAuthnException;

/**
 * Passkeys (WebAuthn) as an alternative second factor to TOTP — orthogonal
 * to the vault key (§0.3), exactly like TwoFactorAuthenticationService: this
 * gates the login session, not access to client-side-encrypted data. The
 * private key never leaves the authenticator; the server only stores the
 * public key and verifies signatures.
 *
 * Both ceremonies are two-step (options → verify) and the one-time challenge
 * lives in the session between the steps, namespaced per ceremony so a
 * registration challenge can never satisfy a login assertion or vice versa.
 * Challenges are consumed on first use, successful or not.
 *
 * The browser is the relying party's only trusted channel for the origin
 * check, so the RP id is always this request's own host (rpId must be a
 * registrable suffix of the origin the browser reports; lbuchs verifies the
 * rpIdHash and the clientData origin against it).
 */
class PasskeyService
{
    private const REGISTRATION_CHALLENGE_KEY = 'passkeys.registration.challenge';

    private const LOGIN_CHALLENGE_KEY = 'passkeys.login.challenge';

    /** @return array<string, mixed> JSON-ready PublicKeyCredentialCreationOptions (binary fields base64url). */
    public function registrationOptions(Request $request, User $user): array
    {
        $webAuthn = $this->webAuthn($request);

        $options = $webAuthn->getCreateArgs(
            // Stable, opaque, non-PII handle for this account (the uuid's
            // raw bytes) — never the name/email.
            hex2bin(str_replace('-', '', $user->id)),
            // Shown by the browser/OS when picking a passkey — email is
            // optional, name always exists (same fallback as the TOTP QR).
            $user->email ?? $user->name,
            $user->name,
            timeout: 60,
            requireResidentKey: false,
            requireUserVerification: 'preferred',
            excludeCredentialIds: $user->passkeys()->pluck('credential_id')
                ->map(fn (string $id) => $this->decodeId($id))
                ->all(),
        );

        $request->session()->put(self::REGISTRATION_CHALLENGE_KEY, $webAuthn->getChallenge()->getBinaryString());

        return json_decode(json_encode($options), true);
    }

    /**
     * Verifies the attestation against the stored challenge and persists the
     * new credential.
     *
     * @param  array{client_data_json: string, attestation_object: string, transports?: array<int, string>|null}  $payload
     *
     * @throws \InvalidArgumentException if the attestation does not verify
     */
    public function register(Request $request, User $user, string $name, array $payload): Passkey
    {
        $challenge = $request->session()->pull(self::REGISTRATION_CHALLENGE_KEY);

        if (! is_string($challenge)) {
            throw new \InvalidArgumentException('No passkey registration in progress.');
        }

        try {
            $data = $this->webAuthn($request)->processCreate(
                $this->decodeId($payload['client_data_json']),
                $this->decodeId($payload['attestation_object']),
                new ByteBuffer($challenge),
                requireUserVerification: false,
                requireUserPresent: true,
                failIfRootMismatch: false,
            );
        } catch (WebAuthnException $e) {
            throw new \InvalidArgumentException('Passkey registration could not be verified.', 0, $e);
        }

        $credentialId = $this->encodeId($data->credentialId);

        if (Passkey::withTrashed()->where('credential_id', $credentialId)->exists()) {
            throw new \InvalidArgumentException('That passkey is already registered.');
        }

        return $user->passkeys()->create([
            'name' => $name,
            'credential_id' => $credentialId,
            'public_key' => $data->credentialPublicKey,
            'sign_count' => (int) ($data->signatureCounter ?? 0),
            'transports' => array_values(array_filter($payload['transports'] ?? [], 'is_string')) ?: null,
        ]);
    }

    /**
     * Assertion options restricted to this user's own credentials — the
     * user is already identified by the password step, so no discoverable
     * credentials are needed.
     *
     * @return array<string, mixed>|null null if the user has no passkeys
     */
    public function loginOptions(Request $request, User $user): ?array
    {
        $credentialIds = $user->passkeys()->pluck('credential_id')
            ->map(fn (string $id) => $this->decodeId($id))
            ->all();

        if ($credentialIds === []) {
            return null;
        }

        $webAuthn = $this->webAuthn($request);
        $options = $webAuthn->getGetArgs($credentialIds, timeout: 60, requireUserVerification: 'preferred');

        $request->session()->put(self::LOGIN_CHALLENGE_KEY, $webAuthn->getChallenge()->getBinaryString());

        return json_decode(json_encode($options), true);
    }

    /**
     * Verifies an assertion for one of this user's passkeys. Returns false
     * (never throws) on any mismatch so the caller can answer uniformly.
     *
     * @param  array{id: string, client_data_json: string, authenticator_data: string, signature: string}  $payload
     */
    public function verifyLogin(Request $request, User $user, array $payload): bool
    {
        $challenge = $request->session()->pull(self::LOGIN_CHALLENGE_KEY);

        if (! is_string($challenge)) {
            return false;
        }

        $passkey = $user->passkeys()->where('credential_id', $payload['id'])->first();

        if (! $passkey) {
            return false;
        }

        $webAuthn = $this->webAuthn($request);

        try {
            $webAuthn->processGet(
                $this->decodeId($payload['client_data_json']),
                $this->decodeId($payload['authenticator_data']),
                $this->decodeId($payload['signature']),
                $passkey->public_key,
                new ByteBuffer($challenge),
                $passkey->sign_count > 0 ? $passkey->sign_count : null,
                requireUserVerification: false,
                requireUserPresent: true,
            );
        } catch (WebAuthnException) {
            return false;
        }

        $passkey->forceFill([
            'sign_count' => max($passkey->sign_count, (int) $webAuthn->getSignatureCounter()),
            'last_used_at' => now(),
        ])->save();

        return true;
    }

    private function webAuthn(Request $request): WebAuthn
    {
        // Last arg: base64url (not plain base64) for every ByteBuffer that
        // is JSON-serialized into the options sent to the browser.
        return new WebAuthn(config('app.name'), $request->getHost(), ['none'], true);
    }

    private function encodeId(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    private function decodeId(string $base64url): string
    {
        return base64_decode(strtr($base64url, '-_', '+/').str_repeat('=', (4 - strlen($base64url) % 4) % 4), true) ?: '';
    }
}
