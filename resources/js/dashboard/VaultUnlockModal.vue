<script setup lang="ts">
/**
 * The one vault-unlock dialog for the whole app — mounted once (see
 * DashboardLayout.vue), opened via requestUnlock() (vaultModal.ts) from
 * anywhere that needs the vault: a whole page (VaultGate.vue) or a single
 * field (e.g. SleepExceptions.vue's private note). Closing it for any
 * reason (passphrase accepted, Cancel, Esc, backdrop click) resolves every
 * pending requestUnlock() call with whether the vault actually ended up
 * unlocked — callers must never render gated content on a false result.
 */
import { BButton, BFormGroup, BFormInput, BModal } from 'bootstrap-vue-next';
import { ref } from 'vue';
import { settleUnlockRequests, vaultModalOpen } from './vaultModal';
import { useVault } from './useVault';

const { vaultUnlocked, unlock } = useVault();

const passphrase = ref('');
const error = ref('');
const unlocking = ref(false);

async function submit(): Promise<void> {
  error.value = '';
  unlocking.value = true;

  try {
    await unlock(passphrase.value);
    vaultModalOpen.value = false;
  } catch (e) {
    console.error(e);
    error.value = 'Wrong passphrase, or the vault could not be read. Please try again.';
    passphrase.value = '';
  } finally {
    unlocking.value = false;
  }
}

// Fires on every close, whatever the trigger (the submit() success path
// above, the Cancel button, Esc, or a backdrop click) — settling with
// vaultUnlocked's actual current value is what makes cancel/dismiss
// correctly resolve every waiting requestUnlock() as false.
function onHide(): void {
  passphrase.value = '';
  error.value = '';
  settleUnlockRequests(vaultUnlocked.value);
}
</script>

<template>
  <BModal
    v-model="vaultModalOpen"
    title="Unlock your vault"
    no-footer
    @hide="onHide"
  >
    <p class="small text-muted">
      Your passphrase never leaves this browser. It's used to derive the key
      that decrypts your connections and link labels.
    </p>
    <form @submit.prevent="submit">
      <BFormGroup label="Passphrase" label-for="vault-modal-passphrase" class="mb-3">
        <BFormInput id="vault-modal-passphrase" v-model="passphrase" type="password" autofocus required />
      </BFormGroup>
      <div v-if="error" class="text-danger small mb-2">{{ error }}</div>
      <div class="d-flex gap-2">
        <BButton type="submit" variant="primary" :disabled="unlocking">
          {{ unlocking ? 'Unlocking…' : 'Unlock' }}
        </BButton>
        <BButton variant="outline-secondary" :disabled="unlocking" @click="vaultModalOpen = false">
          Cancel
        </BButton>
      </div>
    </form>
  </BModal>
</template>
