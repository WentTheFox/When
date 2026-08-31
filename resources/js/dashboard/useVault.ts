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
  unlockVault,
} from './vault';

export const vaultUnlocked = ref(isVaultUnlocked());

export async function unlock(passphrase: string): Promise<void> {
  await unlockVault(passphrase);
  vaultUnlocked.value = true;
}

export function lock(): void {
  lockVault();
  vaultUnlocked.value = false;
}

export function useVault() {
  return { vaultUnlocked, unlock, lock, createRecordKey, getRecordKey, deleteRecordKey };
}
