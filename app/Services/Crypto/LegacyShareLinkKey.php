<?php

namespace App\Services\Crypto;

/**
 * §0.5 legacy-token key derivation. Rather than storing (or redirecting to
 * deliver) a separate content key for a migrated share link, the key is
 * derived deterministically from the token itself via HKDF (RFC 5869, PHP's
 * native hash_hkdf() — not custom crypto), the same way
 * resources/js/crypto/legacyShareLinkKey.ts derives it client-side via
 * WebCrypto's native HKDF. Both sides need only the token, which is already
 * the visible URL path segment — no fragment, no redirect, no stored key.
 *
 * This is a deliberate, narrower trust trade-off than a normal share link's
 * random client-generated key: the token is visible to (and was already
 * being served by) the old app's own request handling, and PLAN.md §0.2
 * already documents that the calendar tier isn't protected against a
 * compromised production runtime — a server that can derive this key on
 * demand is not meaningfully different from a server that already holds
 * every other share link's key via content_key_ciphertext. It only matters
 * for legacy tokens (never for normal share links, which keep their random
 * client-generated key and never derive from anything server-visible).
 *
 * If you touch this file's SALT/INFO or algorithm, touch
 * legacyShareLinkKey.ts's matching constants too, and re-run
 * tests/Unit/Crypto/LegacyShareLinkKeyInteropTest.php.
 */
class LegacyShareLinkKey
{
    private const SALT = 'WhenTheFox-legacy-share-link-v1';
    private const INFO = 'content-key';

    public static function derive(string $token): string
    {
        return hash_hkdf('sha256', $token, 32, self::INFO, self::SALT);
    }
}
