<script setup lang="ts">
/**
 * The one "are you sure?" dialog for the whole app — mounted once (see
 * DashboardLayout.vue), opened via requestConfirm() (../dashboard/confirmModal.ts)
 * wherever a destructive action used to reach for the browser's native
 * confirm(). Resolves the caller's promise with whether the action was
 * actually confirmed, however the dialog closed.
 */
import { BButton, BModal } from 'bootstrap-vue-next';
import { confirmModalOpen, confirmModalOptions, settleConfirmRequests } from '../dashboard/confirmModal';

function confirm(): void {
  confirmModalOpen.value = false;
  settleConfirmRequests(true);
}

function cancel(): void {
  confirmModalOpen.value = false;
}

// Fires on every close, whatever the trigger — the confirm() path above
// already settled with true by the time this runs (resolvers is emptied on
// first settle, so this second call is a harmless no-op then); for
// Cancel/Esc/backdrop-click it's the only settle that happens, and it must
// resolve with false.
function onHide(): void {
  settleConfirmRequests(false);
}
</script>

<template>
  <BModal
    v-model="confirmModalOpen"
    :title="confirmModalOptions.title"
    no-footer
    @hide="onHide"
  >
    <p v-if="confirmModalOptions.message" class="mb-3">{{ confirmModalOptions.message }}</p>
    <div class="d-flex gap-2">
      <BButton :variant="confirmModalOptions.variant ?? 'primary'" @click="confirm">
        {{ confirmModalOptions.confirmText ?? 'Confirm' }}
      </BButton>
      <BButton variant="outline-secondary" @click="cancel">
        {{ confirmModalOptions.cancelText ?? 'Cancel' }}
      </BButton>
    </div>
  </BModal>
</template>
