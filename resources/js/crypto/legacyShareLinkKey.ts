import { utf8ToBytes } from './encoding';

/**
 * §0.5 legacy-token key derivation via WebCrypto's native HKDF (RFC 5869) —
 * matches App\Services\Crypto\LegacyShareLinkKey.php byte-for-byte. Both
 * sides derive the same AES-256 key from just the token, which is already
 * the visible URL path segment for a migrated share link, instead of
 * transmitting or storing a separate key. See that PHP class's doc comment
 * for the trust-model reasoning. If you touch SALT/INFO here, touch the PHP
 * side too, and re-run the interop test.
 */
const SALT = 'WhenTheFox-legacy-share-link-v1';
const INFO = 'content-key';

export async function deriveLegacyShareLinkKey(token: string): Promise<CryptoKey> {
  const baseKey = await crypto.subtle.importKey('raw', utf8ToBytes(token), 'HKDF', false, [
    'deriveKey',
  ]);

  return crypto.subtle.deriveKey(
    {
      name: 'HKDF',
      hash: 'SHA-256',
      salt: utf8ToBytes(SALT),
      info: utf8ToBytes(INFO),
    },
    baseKey,
    { name: 'AES-GCM', length: 256 },
    // Extractable: the token this key derives from is already public (it's
    // the URL path segment), so there's no secrecy benefit to locking the
    // derived key inside a non-extractable CryptoKey — same reasoning as
    // aesgcm.ts's importAesKey.
    true,
    ['decrypt'],
  );
}
