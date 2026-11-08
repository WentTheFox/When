/**
 * Reactive Vue wrapper around vault.ts's module-level vault state (§0.3).
 * The underlying crypto stays framework-agnostic in vault.ts; this just
 * exposes it as a ref so Vue components can react to unlock/lock without
 * each one re-deriving its own DOM event wiring (that was the "manual JS"
 * shell.ts used to do).
 */
import { ref } from 'vue';
import {
  createRecordKey,
  deleteRecordKey,
  getRecordKey,
  isVaultUnlocked,
  lockVault,
  restoreVault,
  unlockVault,
} from './vault';
import { setAutoLockHandler, startAutoLock, stopAutoLock } from './vaultAutoLock';

export const vaultUnlocked = ref(isVaultUnlocked());

export async function unlock(passphrase: string): Promise<void> {
  const session = await unlockVault(passphrase);
  vaultUnlocked.value = true;
  startAutoLock(session.expiresAt);
}

/** Reopens the vault from the persisted key after a reload. Resolves true if it worked. */
export async function restore(): Promise<boolean> {
  const session = await restoreVault();
  if (!session) return false;

  vaultUnlocked.value = true;
  startAutoLock(session.expiresAt);

  return true;
}

export function lock(): void {
  stopAutoLock();
  lockVault();
  vaultUnlocked.value = false;
}

// The idle timer locks through here so the reactive flag flips with it.
setAutoLockHandler(lock);

export function useVault() {
  return { vaultUnlocked, unlock, restore, lock, createRecordKey, getRecordKey, deleteRecordKey };
}
