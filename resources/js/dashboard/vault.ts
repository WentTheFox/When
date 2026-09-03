/**
 * Dashboard's vault-unlock flow (§0.3, Stage 7). Session-authenticated
 * (Laravel session + CSRF via axios's built-in XSRF-TOKEN cookie handling —
 * see bootstrap.js), distinct from both the reverted Sanctum/API-token
 * approach and the operator CLI's direct-Eloquent approach. The server only
 * ever holds and returns the salt + key-ring ciphertext; the vault key and
 * the decrypted ring live only in this module's memory, for the lifetime of
 * the page. Every other dashboard module that needs to encrypt/decrypt
 * (settings labels, share-link labels, Connections CRM) goes through here.
 */
import axios from 'axios';
import {
  decryptKeyRing,
  deriveKeyFromPassphrase,
  encryptKeyRing,
  getKeyFromRing,
  putKeyInRing,
  removeKeyFromRing,
  type KeyRing,
} from '../crypto';

interface VaultResponse {
  passphrase_salt: string;
  key_ring_ciphertext: string;
}

let vaultKey: CryptoKey | null = null;
let keyRing: KeyRing | null = null;

export class VaultLockedError extends Error {
  constructor() {
    super('Vault is locked. Call unlockVault() first.');
    this.name = 'VaultLockedError';
  }
}

export function isVaultUnlocked(): boolean {
  return vaultKey !== null;
}

/** Derives the vault key from the owner's passphrase and decrypts the key ring. Throws on a wrong passphrase. */
export async function unlockVault(passphrase: string): Promise<void> {
  const { data } = await axios.get<VaultResponse>('/dashboard/vault');

  const { keyBytes } = await deriveKeyFromPassphrase(passphrase, data.passphrase_salt);
  const candidateKey = await crypto.subtle.importKey(
    'raw',
    keyBytes,
    { name: 'AES-GCM' },
    false,
    ['encrypt', 'decrypt'],
  );

  // Throws (DecryptionFailedError) on a wrong passphrase — propagates to the caller.
  const ring = await decryptKeyRing(candidateKey, data.key_ring_ciphertext);

  vaultKey = candidateKey;
  keyRing = ring;
}

export function lockVault(): void {
  vaultKey = null;
  keyRing = null;
}

function requireVaultKey(): CryptoKey {
  if (!vaultKey) {
    throw new VaultLockedError();
  }
  return vaultKey;
}

function requireKeyRing(): KeyRing {
  if (!keyRing) {
    throw new VaultLockedError();
  }
  return keyRing;
}

/** Persists the current in-memory key ring, re-encrypted, to the server. */
async function persistKeyRing(): Promise<void> {
  const ciphertext = await encryptKeyRing(requireVaultKey(), requireKeyRing());
  await axios.patch('/dashboard/vault', { key_ring_ciphertext: ciphertext });
}

/** Generates a fresh AES-256 content key, stores it in the ring under `recordId`, and persists the ring. */
export async function createRecordKey(recordId: string): Promise<CryptoKey> {
  const key = await crypto.subtle.generateKey({ name: 'AES-GCM', length: 256 }, true, [
    'encrypt',
    'decrypt',
  ]);
  keyRing = await putKeyInRing(requireKeyRing(), recordId, key);
  await persistKeyRing();
  return key;
}

export async function getRecordKey(recordId: string): Promise<CryptoKey> {
  return getKeyFromRing(requireKeyRing(), recordId);
}

export async function deleteRecordKey(recordId: string): Promise<void> {
  keyRing = removeKeyFromRing(requireKeyRing(), recordId);
  await persistKeyRing();
}

/**
 * Decrypts the key ring for a GIVEN passphrase without touching this
 * module's own unlocked-vault state — used by the change-master-password
 * flow, which needs the raw KeyRing to re-encrypt it under a new passphrase,
 * not just a locked/unlocked flag. Throws DecryptionFailedError (from
 * decryptKeyRing) on a wrong passphrase; that failure itself IS the "is this
 * really your current password" check, no separate server round-trip needed.
 */
export async function decryptVaultKeyRingWithPassphrase(passphrase: string): Promise<KeyRing> {
  const { data } = await axios.get<VaultResponse>('/dashboard/vault');

  const { keyBytes } = await deriveKeyFromPassphrase(passphrase, data.passphrase_salt);
  const candidateKey = await crypto.subtle.importKey(
    'raw',
    keyBytes,
    { name: 'AES-GCM' },
    false,
    ['encrypt', 'decrypt'],
  );

  return decryptKeyRing(candidateKey, data.key_ring_ciphertext);
}
