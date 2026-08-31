<?php

namespace App\Services\Crypto;

/**
 * Every share link's content key derivation. Rather than storing a separate
 * random content key server-side (the earlier "modern" mode, since removed)
 * or redirecting to deliver one, the key is derived deterministically from
 * the link's own public identifier (`legacy_token ?? id`) via HKDF (RFC
 * 5869, PHP's native hash_hkdf() — not custom crypto), the same way
 * resources/js/crypto/legacyShareLinkKey.ts derives it client-side via
 * WebCrypto's native HKDF. Both sides need only that identifier, which is
 * already the visible URL path segment — no fragment, no passphrase, no
 * stored key. Originally built only for pre-migration legacy tokens (§0.5)
 * — the class name is the one thing still reflecting that history — but now
 * used unconditionally for every share link.
 *
 * This is a deliberate, narrower trust trade-off than a stored random key
 * ever was: the identifier is visible to (and already served by) this app's
 * own request handling, and PLAN.md §0.2 already documents that the
 * calendar tier isn't protected against a compromised production runtime —
 * a server that can derive this key on demand is not meaningfully different
 * from a server that used to hold every share link's key via
 * content_key_ciphertext anyway.
 *
 * If you touch this file's SALT/INFO or algorithm, touch
 * legacyShareLinkKey.ts's matching constants too, and re-run
 * tests/Unit/Crypto/LegacyShareLinkKeyTest.php's interop fixture case.
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
