import { type Ref, watch } from 'vue';

/**
 * Runs `run` right away if the vault is already unlocked, otherwise as soon as
 * it becomes unlocked (and again on any later unlock).
 *
 * For components that can be on screen *before* the unlock — a reload with a
 * record in the URL shows its form under the locked overlay while the unlock
 * dialog is open — and must decrypt once the keys exist rather than try once
 * on mount, fail because the vault is locked, and stay broken.
 *
 * Call it from setup(): the watcher goes away with the component.
 */
export function onVaultUnlocked(unlocked: Ref<boolean>, run: () => void): void {
  watch(unlocked, (isUnlocked) => {
    if (isUnlocked) run();
  }, { immediate: true });
}
