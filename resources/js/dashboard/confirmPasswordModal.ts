/**
 * Single global "confirm your master password" dialog, mirroring
 * vaultModal.ts's shape but for a different purpose: it doesn't unlock
 * anything client-side, it derives and hands back the login verifier so a
 * caller (data export, account deletion) can attach it to their own
 * server-verified request. requestConfirmation() opens the dialog and
 * resolves with the derived verifier string once confirmed, or null if
 * cancelled/dismissed by any means (Cancel, Esc, backdrop click).
 */
import { ref } from 'vue';

export const confirmPasswordModalOpen = ref(false);

let resolvers: ((verifier: string | null) => void)[] = [];

export function requestConfirmation(): Promise<string | null> {
  confirmPasswordModalOpen.value = true;

  return new Promise((resolve) => resolvers.push(resolve));
}

/** Called by ConfirmPasswordModal.vue whenever it closes, regardless of why. */
export function settleConfirmationRequests(verifier: string | null): void {
  const pending = resolvers;
  resolvers = [];
  pending.forEach((resolve) => resolve(verifier));
}
