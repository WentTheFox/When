/**
 * Single global vault-unlock dialog, shared by every page/component that
 * needs the vault instead of each one rolling its own inline unlock form.
 * requestUnlock() opens it (a no-op returning immediately if the vault is
 * already unlocked) and resolves once the dialog closes, by whatever means
 * (passphrase accepted, Cancel, Esc, backdrop click) — true only if the
 * vault actually ended up unlocked, false otherwise. Callers must never
 * render vault-tier content until that promise resolves true.
 */
import { ref } from 'vue';
import { isVaultUnlocked } from './vault';

export const vaultModalOpen = ref(false);

let resolvers: ((unlocked: boolean) => void)[] = [];

export function requestUnlock(): Promise<boolean> {
  if (isVaultUnlocked()) return Promise.resolve(true);

  vaultModalOpen.value = true;

  return new Promise((resolve) => resolvers.push(resolve));
}

/** Called by VaultUnlockModal.vue whenever it closes, regardless of why. */
export function settleUnlockRequests(unlocked: boolean): void {
  const pending = resolvers;
  resolvers = [];
  pending.forEach((resolve) => resolve(unlocked));
}
