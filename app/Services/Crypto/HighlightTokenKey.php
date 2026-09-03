<?php

namespace App\Services\Crypto;

/**
 * Every share link's content key derivation. Rather than storing a separate
 * random content key server-side (the earlier "modern" mode, since removed)
 * or redirecting to deliver one, the key is derived deterministically from
 * the link's own public identifier (`highlight_token ?? id`) via HKDF (RFC
 * 5869, PHP's native hash_hkdf() — not custom crypto), the same way
 * resources/js/crypto/highlightTokenKey.ts derives it client-side via
 * WebCrypto's native HKDF. Both sides need only that identifier, which is
 * already the visible URL path segment — no fragment, no passphrase, no
 * stored key. Named after calendar_highlight_tokens.token, the old app's
 * own name for this same public identifier — every share link gets one at
 * creation now (see ShareLinkManagementController::store()), not just
 * pre-migration imports.
 *
 * This is a deliberate, narrower trust trade-off than a stored random key
 * ever was: the identifier is visible to (and already served by) this app's
 * own request handling, and PLAN.md §0.2 already documents that the
 * calendar tier isn't protected against a compromised production runtime —
 * a server that can derive this key on demand is not meaningfully different
 * from a server that used to hold every share link's key via
 * content_key_ciphertext anyway.
 *
 * SALT/INFO below are cryptographic domain-separation constants already
 * baked into every existing share link's derived key — their string values
 * must never change (only this class/file's own name has), or every
 * existing share link URL out in the wild stops decrypting. If you do need
 * to touch the algorithm itself, touch highlightTokenKey.ts's matching
 * constants too, and re-run tests/Unit/Crypto/HighlightTokenKeyTest.php's
 * interop fixture case.
 */
class HighlightTokenKey
{
    private const SALT = 'WhenTheFox-legacy-share-link-v1';

    private const INFO = 'content-key';

    public static function derive(string $token): string
    {
        return hash_hkdf('sha256', $token, 32, self::INFO, self::SALT);
    }
}
