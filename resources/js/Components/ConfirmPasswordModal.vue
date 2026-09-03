<script setup lang="ts">
/**
 * The one "confirm your master password" dialog for the whole app —
 * mounted once (see DashboardLayout.vue), opened via requestConfirmation()
 * (../dashboard/confirmPasswordModal.ts) from anywhere that needs to
 * re-prove password knowledge before a sensitive account action (data
 * export, account deletion). Unlike VaultUnlockModal, the value entered
 * here IS sent to the server (as the derived login verifier, never the
 * password itself) for a real Hash::check — say so plainly in the copy
 * below, don't reuse VaultUnlockModal's "never leaves this browser" claim.
 */
import { usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { BButton, BFormGroup, BModal } from 'bootstrap-vue-next';
import { ref } from 'vue';
import { deriveLoginVerifier } from '../crypto';
import { confirmPasswordModalOpen, settleConfirmationRequests } from '../dashboard/confirmPasswordModal';
import type { SharedPageProps } from '../sharedPageProps';
import PasswordField from './PasswordField.vue';

const page = usePage<SharedPageProps>();

const password = ref('');
const error = ref('');
const confirming = ref(false);

interface LookupResponse {
  id: string;
  saltVersion: 'id' | 'email';
}

async function submit(): Promise<void> {
  error.value = '';
  confirming.value = true;

  try {
    const name = page.props.auth.user?.name;
    if (!name) {
      throw new Error('Not logged in.');
    }

    // The verifier salt is derived from the account's id, not its name —
    // this tiny round-trip resolves that id, same as Login.vue does. A
    // still-legacy (email-salted) account can't be resolved from just the
    // shared `name` prop this modal has access to, so this always uses the
    // id-based salt; that's a no-op mismatch only for an account that
    // hasn't yet done the one-time migration login() already performs, a
    // rare and self-healing edge case not worth a bigger fix here.
    const { data: lookup } = await axios.post<LookupResponse>('/login/lookup', { identifier: name });

    const verifier = await deriveLoginVerifier(password.value, lookup.id);

    confirmPasswordModalOpen.value = false;
    settleConfirmationRequests(verifier);
  } catch (e) {
    console.error(e);
    error.value = 'Something went wrong. Please try again.';
  } finally {
    confirming.value = false;
  }
}

function cancel(): void {
  confirmPasswordModalOpen.value = false;
}

// Fires on every close, whatever the trigger — the submit() success path
// above already settled with a verifier by the time this runs (resolvers is
// emptied on first settle, so this second call is a harmless no-op then);
// for Cancel/Esc/backdrop-click it's the only settle that happens, and it
// must resolve with null.
function onHide(): void {
  password.value = '';
  error.value = '';
  settleConfirmationRequests(null);
}
</script>

<template>
  <BModal
    v-model="confirmPasswordModalOpen"
    title="Confirm your master password"
    no-footer
    @hide="onHide"
  >
    <p class="small text-muted">
      Re-enter your master password to confirm this action. We verify it the
      same way as signing in — we never see the password itself, only a
      value derived from it.
    </p>
    <form @submit.prevent="submit">
      <BFormGroup label="Master password" label-for="confirm-password-modal-input" class="mb-3">
        <PasswordField id="confirm-password-modal-input" v-model="password" autofocus required />
      </BFormGroup>
      <div v-if="error" class="text-danger small mb-2">{{ error }}</div>
      <div class="d-flex gap-2">
        <BButton type="submit" variant="primary" :disabled="confirming">
          {{ confirming ? 'Confirming…' : 'Confirm' }}
        </BButton>
        <BButton variant="outline-secondary" :disabled="confirming" @click="cancel">
          Cancel
        </BButton>
      </div>
    </form>
  </BModal>
</template>
