<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP 2FA (passkeys, the alternative second factor, live in PasskeyService;
 * recovery codes are shared between the two) — orthogonal to the vault key (§0.3): this gates the login
 * session, not access to client-side-encrypted data. Secrets/recovery codes
 * are encrypted at rest with the app key (standard operational-security
 * practice), which is a different concern from the calendar-url/Connections
 * tiers in PLAN.md §0.
 */
class TwoFactorAuthenticationService
{
    private const RECOVERY_CODE_COUNT = 8;

    public function __construct(private readonly Google2FA $google2fa) {}

    /** Generates a fresh (unconfirmed) secret and stores it on the user. */
    public function generateSecret(User $user): string
    {
        $secret = $this->google2fa->generateSecretKey();

        $user->forceFill([
            'two_factor_secret' => $secret,
            // Recovery codes are shared with passkeys (see
            // ensureRecoveryCodes()) — only a TOTP-only account's codes die
            // with its old secret.
            'two_factor_recovery_codes' => $user->passkeys()->exists() ? $user->two_factor_recovery_codes : null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return $secret;
    }

    public function getQrCodeUrl(User $user): string
    {
        return $this->google2fa->getQRCodeUrl(
            config('app.name'),
            // Just a label shown in the authenticator app — email is
            // optional now, so fall back to name, which always exists.
            $user->email ?? $user->name,
            $user->two_factor_secret,
        );
    }

    public function verifyCode(User $user, string $code): bool
    {
        if (! $user->two_factor_secret) {
            return false;
        }

        return $this->google2fa->verifyKey($user->two_factor_secret, $code);
    }

    /**
     * Confirms setup and, if the account has no recovery codes yet (e.g. from
     * an earlier passkey), generates them. Returns the plaintext codes, shown
     * once — empty if existing codes were kept.
     */
    public function confirm(User $user, string $code): array
    {
        if (! $this->verifyCode($user, $code)) {
            throw new \InvalidArgumentException('Invalid TOTP code.');
        }

        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        return $this->ensureRecoveryCodes($user) ?? [];
    }

    /**
     * Turns TOTP off. Passkeys are independent of it and keep working, so
     * recovery codes are only dropped once no second factor remains.
     */
    public function disable(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        $this->pruneRecoveryCodes($user);
    }

    /**
     * Recovery codes back up *every* second factor (TOTP or passkey), so
     * whichever is enabled first creates them. Returns the new plaintext
     * codes (shown once), or null if the account already has some.
     */
    public function ensureRecoveryCodes(User $user): ?array
    {
        if (! empty($user->two_factor_recovery_codes)) {
            return null;
        }

        $recoveryCodes = $this->generateRecoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => $recoveryCodes])->save();

        return $recoveryCodes;
    }

    /** Drops recovery codes once the account has no second factor left to back up. */
    public function pruneRecoveryCodes(User $user): void
    {
        if (! $user->hasTwoFactor()) {
            $user->forceFill(['two_factor_recovery_codes' => null])->save();
        }
    }

    public function redeemRecoveryCode(User $user, string $code): bool
    {
        $codes = $user->two_factor_recovery_codes ?? [];
        $index = array_search($code, $codes, true);

        if ($index === false) {
            return false;
        }

        unset($codes[$index]);
        $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();

        return true;
    }

    private function generateRecoveryCodes(): array
    {
        return collect(range(1, self::RECOVERY_CODE_COUNT))
            ->map(fn () => Str::random(10).'-'.Str::random(10))
            ->all();
    }
}
