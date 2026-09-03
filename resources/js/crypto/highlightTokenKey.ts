import { utf8ToBytes } from './encoding';

/**
 * Every share link's content key derivation, via WebCrypto's native HKDF
 * (RFC 5869) — matches App\Services\Crypto\HighlightTokenKey.php
 * byte-for-byte. Both sides derive the same AES-256 key from just the
 * link's own id/highlight_token, which is already the visible URL path
 * segment, instead of transmitting or storing a separate key. Named after
 * calendar_highlight_tokens.token, the old app's own name for this same
 * public identifier — every share link gets one at creation now, not just
 * pre-migration imports. See the PHP class's doc comment for the
 * trust-model reasoning and why SALT/INFO below must never change.
 */
const SALT = 'WhenTheFox-legacy-share-link-v1';
const INFO = 'content-key';

export async function deriveHighlightTokenKey(token: string): Promise<CryptoKey> {
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
