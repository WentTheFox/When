/**
 * Single global vault-unlock dialog, shared by every page/component that
 * needs the vault instead of each one rolling its own inline unlock form.
 * requestUnlock() opens it (a no-op returning immediately if the vault is
 * already unlocked) and resolves once the dialog closes, by whatever means
 * (passphrase accepted, Cancel, Esc, backdrop click) — true only if the
 * vault actually ended up unlocked, false otherwise. Callers must never
 * render vault-tier content until that promise resolves true.
 */
import { ref, watch } from 'vue';
import { isVaultUnlocked } from './vault';
import { vaultUnlocked } from './useVault';

export const vaultModalOpen = ref(false);

/**
 * Set by Login.vue/Register.vue right before they submit, synchronously —
 * i.e. guaranteed true before Inertia can possibly swap to a vault-gated
 * page — and cleared once their own background unlock() attempt (fired
 * from onSuccess, using the master password already in memory from that
 * same submission) settles either way. Lets requestUnlock() below wait for
 * that attempt instead of popping the passphrase modal the instant a
 * freshly-logged-in/registered owner's first dashboard page mounts: since
 * Inertia only fires a form submission's onSuccess *after* the new page
 * has already rendered (and thus after this module's requestUnlock() has
 * already been called by that page's VaultGate), the background attempt
 * can never win a race against the page mounting — this flag sidesteps
 * needing to win one at all.
 */
export const autoUnlockPending = ref(false);

/** Safety net only — autoUnlockPending is always cleared by its setter's own finally(), this just bounds how long a caller waits if that setter is ever buggy or the background attempt hangs. */
const AUTO_UNLOCK_TIMEOUT_MS = 8000;

let resolvers: ((unlocked: boolean) => void)[] = [];

export function requestUnlock(): Promise<boolean> {
  if (isVaultUnlocked()) return Promise.resolve(true);

  if (autoUnlockPending.value) {
    return waitForAutoUnlock();
  }

  vaultModalOpen.value = true;

  return new Promise((resolve) => resolvers.push(resolve));
}

/** Called by VaultUnlockModal.vue whenever it closes, regardless of why. */
export function settleUnlockRequests(unlocked: boolean): void {
  const pending = resolvers;
  resolvers = [];
  pending.forEach((resolve) => resolve(unlocked));
}

function waitForAutoUnlock(): Promise<boolean> {
  return new Promise((resolve) => {
    let settled = false;

    const finish = (unlocked: boolean) => {
      if (settled) return;
      settled = true;
      stop();
      clearTimeout(timer);
      resolve(unlocked);
    };

    const stop = watch([vaultUnlocked, autoUnlockPending], ([unlocked, pending]) => {
      if (unlocked) {
        finish(true);
      } else if (!pending) {
        // The background attempt finished without unlocking (e.g. the
        // /dashboard/vault fetch failed) — fall back to asking normally.
        vaultModalOpen.value = true;
        resolvers.push(finish);
      }
    }, { immediate: true });

    const timer = setTimeout(() => {
      if (settled) return;
      vaultModalOpen.value = true;
      resolvers.push(finish);
    }, AUTO_UNLOCK_TIMEOUT_MS);
  });
}
