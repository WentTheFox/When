/**
 * Keeps the vault unlocked across page reloads, with an expiry the server
 * enforces.
 *
 * IndexedDB has no TTL, and an `expiresAt` field stored next to the data
 * would only be a polite request — anyone with script access could edit it.
 * So what's stored here is useless without a second secret that only lives in
 * the Laravel session: the derived vault key bytes, AES-GCM-encrypted under
 * a random "wrap key" that GET /dashboard/vault/wrap-key hands out only until
 * the owner's auto-lock deadline (and that logout / the lock button / the
 * deadline itself destroy). After that, this blob can't be decrypted by
 * anyone, even with a copy of the whole IndexedDB.
 *
 * Never stored: the master password, the raw vault key, the key ring.
 * Every function degrades to "no persistence" when IndexedDB is unavailable
 * (private windows, blocked site data) — the vault then just behaves as it
 * always did and relocks on reload.
 */
import axios from 'axios';
import { base64ToBytes, bytesToBase64, decryptString, encryptString } from '../crypto';

const DB_NAME = 'when-vault';
const STORE = 'session';
const RECORD_KEY = 'wrapped-vault-key';

export interface WrapKeyResponse {
  /** base64 of the 32-byte session wrap key. */
  key: string;
  /** Epoch ms on the server's clock; null = never expires. */
  expires_at: number | null;
  server_now: number;
}

function openDb(): Promise<IDBDatabase> {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open(DB_NAME, 1);
    request.onupgradeneeded = () => request.result.createObjectStore(STORE);
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });
}

async function withStore<T>(mode: IDBTransactionMode, run: (store: IDBObjectStore) => IDBRequest<T>): Promise<T> {
  const db = await openDb();
  try {
    return await new Promise<T>((resolve, reject) => {
      const request = run(db.transaction(STORE, mode).objectStore(STORE));
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error);
    });
  } finally {
    db.close();
  }
}

async function readBlob(): Promise<string | null> {
  try {
    const value = await withStore('readonly', (store) => store.get(RECORD_KEY));
    return typeof value === 'string' ? value : null;
  } catch {
    return null;
  }
}

async function writeBlob(blob: string): Promise<void> {
  await withStore('readwrite', (store) => store.put(blob, RECORD_KEY));
}

/** Drops the stored blob (best effort — a failure leaves something undecryptable-after-expiry behind, nothing more). */
export async function clearPersistedVaultKey(): Promise<void> {
  try {
    await withStore('readwrite', (store) => store.delete(RECORD_KEY));
  } catch {
    // Nothing useful to do; the server-side wrap key is the real gate.
  }
}

async function importWrapKey(wrapKeyBase64: string): Promise<CryptoKey> {
  return crypto.subtle.importKey('raw', base64ToBytes(wrapKeyBase64), { name: 'AES-GCM' }, false, [
    'encrypt',
    'decrypt',
  ]);
}

/**
 * Asks the server for a fresh wrap key and stores `keyBytes` encrypted under
 * it. Returns the server's expiry info, or null if persistence isn't
 * possible (the caller then carries on with a memory-only unlock).
 */
export async function persistVaultKey(keyBytes: Uint8Array<ArrayBuffer>): Promise<WrapKeyResponse | null> {
  if (typeof indexedDB === 'undefined') return null;

  try {
    const { data } = await axios.get<WrapKeyResponse>('/dashboard/vault/wrap-key', { params: { create: 1 } });
    const wrapKey = await importWrapKey(data.key);
    await writeBlob(await encryptString(wrapKey, bytesToBase64(keyBytes)));

    return data;
  } catch {
    await clearPersistedVaultKey();
    return null;
  }
}

export interface RestoredVaultKey {
  keyBytes: Uint8Array<ArrayBuffer>;
  expiresAt: number | null;
  serverNow: number;
}

/**
 * Recovers the vault key bytes if (and only if) the server still releases the
 * wrap key. Every failure — no blob, expired, tampered — clears the blob and
 * returns null.
 */
export async function restorePersistedVaultKey(): Promise<RestoredVaultKey | null> {
  if (typeof indexedDB === 'undefined') return null;

  const blob = await readBlob();
  if (blob === null) return null;

  try {
    const response = await axios.get<WrapKeyResponse | ''>('/dashboard/vault/wrap-key');

    if (response.status === 204 || !response.data) {
      await clearPersistedVaultKey();
      return null;
    }

    const wrapKey = await importWrapKey(response.data.key);
    const keyBytes = base64ToBytes(await decryptString(wrapKey, blob));

    return { keyBytes, expiresAt: response.data.expires_at, serverNow: response.data.server_now };
  } catch {
    await clearPersistedVaultKey();
    return null;
  }
}

/** Tells the server to forget the wrap key (and with it, make any stored blob undecryptable). */
export async function forgetWrapKey(): Promise<void> {
  try {
    await axios.delete('/dashboard/vault/wrap-key');
  } catch {
    // Already logged out / offline: logout destroys the session key anyway.
  }
}

/** Slides the server-side expiry (idle mode). Resolves null if the server no longer has a live key. */
export async function touchWrapKey(): Promise<WrapKeyResponse | null> {
  try {
    const response = await axios.post<WrapKeyResponse | ''>('/dashboard/vault/wrap-key/touch');
    return response.status === 204 || !response.data ? null : response.data;
  } catch {
    return null;
  }
}
