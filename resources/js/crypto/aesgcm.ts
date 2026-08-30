import { base64ToBytes, bytesToBase64, utf8ToBytes, bytesToUtf8 } from './encoding';

const IV_LENGTH_BYTES = 12; // 96-bit IV, the recommended size for AES-GCM.

export class DecryptionFailedError extends Error {
  constructor() {
    // A GCM auth-tag mismatch means either the wrong key or tampered
    // ciphertext. Either way, fail loudly and specifically — never fall
    // through to a silent garbage decrypt.
    super('Decryption failed: wrong key or corrupted/tampered ciphertext.');
    this.name = 'DecryptionFailedError';
  }
}

/**
 * Imports raw key bytes as an AES-256-GCM CryptoKey. Extractable: the raw
 * bytes already exist in JS memory at the call site (they were just
 * imported from a fragment, unwrapped, or pulled out of a key ring), so
 * marking the CryptoKey non-extractable wouldn't add real protection — and
 * the key ring (§0.3) needs to re-export keys it holds.
 */
export async function importAesKey(keyBytes: Uint8Array<ArrayBuffer>): Promise<CryptoKey> {
  if (keyBytes.length !== 32) {
    throw new Error(`AES-256 key must be 32 bytes, got ${keyBytes.length}.`);
  }

  return crypto.subtle.importKey('raw', keyBytes, { name: 'AES-GCM' }, true, [
    'encrypt',
    'decrypt',
  ]);
}

/** Generates a fresh random AES-256 content key (e.g. for a connection or a share link). */
export async function generateAesKey(): Promise<CryptoKey> {
  return crypto.subtle.generateKey({ name: 'AES-GCM', length: 256 }, true, [
    'encrypt',
    'decrypt',
  ]);
}

export async function exportAesKey(key: CryptoKey): Promise<Uint8Array<ArrayBuffer>> {
  const raw = await crypto.subtle.exportKey('raw', key);
  return new Uint8Array<ArrayBuffer>(raw);
}

/**
 * Encrypts UTF-8 text and returns a single base64 blob of `iv || ciphertext
 * || tag`, ready to store or transmit as an opaque string.
 */
export async function encryptString(key: CryptoKey, plaintext: string): Promise<string> {
  const iv = new Uint8Array(IV_LENGTH_BYTES);
  crypto.getRandomValues(iv);

  const ciphertext = await crypto.subtle.encrypt(
    { name: 'AES-GCM', iv },
    key,
    utf8ToBytes(plaintext),
  );

  const combined = new Uint8Array(iv.length + ciphertext.byteLength);
  combined.set(iv, 0);
  combined.set(new Uint8Array<ArrayBuffer>(ciphertext), iv.length);

  return bytesToBase64(combined);
}

/**
 * Decrypts a blob produced by {@link encryptString}. Throws
 * {@link DecryptionFailedError} — never returns garbage — on a wrong key or
 * tampered ciphertext.
 */
export async function decryptString(key: CryptoKey, blob: string): Promise<string> {
  const combined = base64ToBytes(blob);

  if (combined.length <= IV_LENGTH_BYTES) {
    throw new DecryptionFailedError();
  }

  const iv = combined.slice(0, IV_LENGTH_BYTES);
  const ciphertext = combined.slice(IV_LENGTH_BYTES);

  try {
    const plaintext = await crypto.subtle.decrypt({ name: 'AES-GCM', iv }, key, ciphertext);
    return bytesToUtf8(new Uint8Array<ArrayBuffer>(plaintext));
  } catch {
    throw new DecryptionFailedError();
  }
}
