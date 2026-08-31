<?php

namespace App\Services\Crypto;

/**
 * §0.3 vault-key derivation, PHP side — used only by the operator CLI (see
 * PLAN.md Stage 7's CLI bullet), which runs on the machine hosting the app
 * and needs to derive the same vault key a browser would via
 * resources/js/crypto/argon2.ts, so it can read/write the same encrypted
 * key ring. Established library (libsodium's sodium_crypto_pwhash, not a
 * custom Argon2 implementation) — same OWASP-recommended profile as the TS
 * side: m=19456 KiB, t=2, p=1, 32-byte output. libsodium's Argon2id API
 * doesn't expose parallelism directly; it's fixed at 1, which is exactly
 * what this profile already uses, so no mismatch there.
 *
 * If you touch this file's parameters, touch argon2.ts's ARGON2ID_PROFILE
 * too, and re-run tests/Unit/Crypto/Argon2idInteropTest.php.
 */
class Argon2id
{
    private const MEMORY_KIB = 19456;
    private const ITERATIONS = 2;
    private const OUTPUT_LENGTH = 32;

    public static function derive(string $passphrase, string $saltBase64): string
    {
        $salt = base64_decode($saltBase64, true);

        if ($salt === false || strlen($salt) !== SODIUM_CRYPTO_PWHASH_SALTBYTES) {
            throw new \InvalidArgumentException(
                'Salt must be a base64-encoded '.SODIUM_CRYPTO_PWHASH_SALTBYTES.'-byte value.'
            );
        }

        return sodium_crypto_pwhash(
            self::OUTPUT_LENGTH,
            $passphrase,
            $salt,
            self::ITERATIONS,
            self::MEMORY_KIB * 1024,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
        );
    }
}
