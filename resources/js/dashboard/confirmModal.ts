/**
 * Single global "are you sure?" dialog, replacing the browser's native
 * confirm() with something that matches the rest of the app's styling and
 * works in every browser the same way — mirrors vaultModal.ts/
 * confirmPasswordModal.ts's shape: requestConfirm() opens it and resolves
 * once it closes, by whatever means (Confirm, Cancel, Esc, backdrop click),
 * true only if the action was actually confirmed.
 */
import { ref } from 'vue';

export interface ConfirmOptions {
  title: string;
  message?: string;
  confirmText?: string;
  cancelText?: string;
  variant?: 'danger' | 'primary';
}

export const confirmModalOpen = ref(false);
export const confirmModalOptions = ref<ConfirmOptions>({ title: '' });

let resolvers: ((confirmed: boolean) => void)[] = [];

export function requestConfirm(options: ConfirmOptions): Promise<boolean> {
  confirmModalOptions.value = options;
  confirmModalOpen.value = true;

  return new Promise((resolve) => resolvers.push(resolve));
}

/** Called by ConfirmModal.vue whenever it closes, regardless of why. */
export function settleConfirmRequests(confirmed: boolean): void {
  const pending = resolvers;
  resolvers = [];
  pending.forEach((resolve) => resolve(confirmed));
}
