import { deriveKeyFromPassphrase, generateSalt } from './argon2';
import { decryptString, encryptString, exportAesKey, generateAesKey, importAesKey } from './aesgcm';
import { base64UrlToBytes, bytesToBase64Url } from './encoding';

const FRAGMENT_KEY_PARAM = 'k';

/**
 * Generates a fresh random content key for a new share link and encodes it
 * for the URL fragment (§0.4 default mode). Fragments are never sent to the
 * server by browsers, so possession of the full link = ability to decrypt.
 */
export async function generateFragmentKey(): Promise<{ key: CryptoKey; encoded: string }> {
  const key = await generateAesKey();
  const raw = await exportAesKey(key);
  return { key, encoded: bytesToBase64Url(raw) };
}

/** Builds `#k=...` for appending to a share link URL. */
export function buildFragment(encodedKey: string): string {
  return `${FRAGMENT_KEY_PARAM}=${encodedKey}`;
}

/** Parses `#k=...` (or a bare fragment value) back into an importable key. */
export async function importKeyFromFragment(fragment: string): Promise<CryptoKey> {
  const raw = fragment.startsWith(`${FRAGMENT_KEY_PARAM}=`)
    ? fragment.slice(FRAGMENT_KEY_PARAM.length + 1)
    : fragment;

  return importAesKey(base64UrlToBytes(raw));
}

export interface WrappedKeyRecord {
  wrappedKey: string;
  salt: string;
}

/**
 * Optional per-link upgrade (§0.4): wraps the content key with an
 * Argon2id(passphrase)-derived key. The wrapped form is safe to store
 * server-side — it can't be unwrapped without the passphrase, which the
 * server never sees.
 */
export async function wrapKeyWithPassphrase(
  contentKey: CryptoKey,
  passphrase: string,
): Promise<WrappedKeyRecord> {
  const salt = generateSalt();
  const { keyBytes } = await deriveKeyFromPassphrase(passphrase, salt);
  const wrappingKey = await importAesKey(keyBytes);

  const rawContentKey = await exportAesKey(contentKey);
  const wrappedKey = await encryptString(
    wrappingKey,
    bytesToBase64UrlOfRaw(rawContentKey),
  );

  return { wrappedKey, salt };
}

/** Reverses {@link wrapKeyWithPassphrase} given the viewer-entered passphrase. */
export async function unwrapKeyWithPassphrase(
  record: WrappedKeyRecord,
  passphrase: string,
): Promise<CryptoKey> {
  const { keyBytes } = await deriveKeyFromPassphrase(passphrase, record.salt);
  const wrappingKey = await importAesKey(keyBytes);

  const encodedContentKey = await decryptString(wrappingKey, record.wrappedKey);
  return importAesKey(base64UrlToBytes(encodedContentKey));
}

function bytesToBase64UrlOfRaw(bytes: Uint8Array<ArrayBuffer>): string {
  return bytesToBase64Url(bytes);
}
