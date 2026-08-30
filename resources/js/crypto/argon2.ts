import { argon2id } from 'hash-wasm';
import { base64ToBytes, bytesToBase64 } from './encoding';

/**
 * OWASP Password Storage Cheat Sheet's minimum recommended Argon2id profile
 * (as of the 2023 revision): m=19 MiB, t=2, p=1, 32-byte output. Used for
 * BOTH the vault key (§0.3) and, per-link, the passphrase-wrapping key
 * (§0.4) — same profile, different salts.
 */
export const ARGON2ID_PROFILE = {
  memorySize: 19456, // KiB
  iterations: 2,
  parallelism: 1,
  hashLength: 32, // bytes -> AES-256 key
} as const;

export interface Argon2idResult {
  /** Raw derived key bytes. Never transmitted, never stored. */
  keyBytes: Uint8Array<ArrayBuffer>;
}

/**
 * Derives a 256-bit key from a passphrase + salt via Argon2id. The salt is
 * the only thing that's ever safe to persist server-side — the passphrase
 * and the derived key never are.
 */
export async function deriveKeyFromPassphrase(
  passphrase: string,
  saltBase64: string,
): Promise<Argon2idResult> {
  if (passphrase.length === 0) {
    throw new Error('Passphrase must not be empty.');
  }

  const salt = base64ToBytes(saltBase64);

  const hashHex = await argon2id({
    password: passphrase,
    salt,
    memorySize: ARGON2ID_PROFILE.memorySize,
    iterations: ARGON2ID_PROFILE.iterations,
    parallelism: ARGON2ID_PROFILE.parallelism,
    hashLength: ARGON2ID_PROFILE.hashLength,
    outputType: 'hex',
  });

  const keyBytes = new Uint8Array(hashHex.match(/.{2}/g)!.map((byte) => parseInt(byte, 16)));

  return { keyBytes };
}

/** Generates a fresh random salt for a new user or a new passphrase-protected share link. */
export function generateSalt(byteLength = 16): string {
  const salt = new Uint8Array(byteLength);
  crypto.getRandomValues(salt);
  return bytesToBase64(salt);
}
