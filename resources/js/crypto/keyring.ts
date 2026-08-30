import { decryptString, encryptString, exportAesKey, importAesKey } from './aesgcm';
import { base64ToBytes, bytesToBase64 } from './encoding';

/**
 * Per-connection and per-share-link content keys, addressed by record id.
 * Encrypted as a whole with the vault key (§0.3) — decrypting the ring once
 * after login is enough to unlock every record without a KDF run per item,
 * and individual keys can be rotated/revoked without re-touching every
 * record's ciphertext.
 */
export type KeyRing = Record<string, string>; // recordId -> base64 raw AES key

/** Encrypts a key ring with the vault key, ready to store server-side. */
export async function encryptKeyRing(vaultKey: CryptoKey, keyRing: KeyRing): Promise<string> {
  return encryptString(vaultKey, JSON.stringify(keyRing));
}

/**
 * Decrypts a key ring with the vault key. Throws (via
 * {@link DecryptionFailedError}) on a wrong passphrase — never silently
 * returns an unusable/garbage ring.
 */
export async function decryptKeyRing(vaultKey: CryptoKey, ciphertext: string): Promise<KeyRing> {
  const json = await decryptString(vaultKey, ciphertext);
  return JSON.parse(json) as KeyRing;
}

export function emptyKeyRing(): KeyRing {
  return {};
}

/** Adds/replaces a record's key in the ring and returns the raw key bytes as base64 for storage in the ring. */
export async function putKeyInRing(
  keyRing: KeyRing,
  recordId: string,
  key: CryptoKey,
): Promise<KeyRing> {
  const rawKey = await exportAesKey(key);
  return { ...keyRing, [recordId]: bytesToBase64(rawKey) };
}

export async function getKeyFromRing(keyRing: KeyRing, recordId: string): Promise<CryptoKey> {
  const rawKeyBase64 = keyRing[recordId];
  if (!rawKeyBase64) {
    throw new Error(`No key found in ring for record "${recordId}".`);
  }
  return importAesKey(base64ToBytes(rawKeyBase64));
}

export function removeKeyFromRing(keyRing: KeyRing, recordId: string): KeyRing {
  const { [recordId]: _removed, ...rest } = keyRing;
  return rest;
}
