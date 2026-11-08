<script setup lang="ts">
/**
 * The Account page's "Vault auto-lock" section: how long the vault stays unlocked while
 * you're idle. The unlocked key is kept (encrypted, with a server-enforced
 * expiry) so reloads don't re-lock it; this is the idle time after which it
 * is dropped and the vault locks again. Its own form, PATCHing only its field.
 */
import { useForm } from '@inertiajs/vue3';
import { BAlert, BButton, BFormGroup, BFormSelect } from 'bootstrap-vue-next';

const props = defineProps<{
  /** Saved idle minutes; null = never auto-lock. */
  vaultLockTimeoutMinutes: number | null;
}>();

/** Select values are strings; 'never' maps to null on the wire. Keep in sync with UpdateSettingsRequest. */
const OPTIONS = [
  { value: '1', text: '1 minute' },
  { value: '5', text: '5 minutes' },
  { value: '15', text: '15 minutes (default)' },
  { value: '30', text: '30 minutes' },
  { value: '60', text: '1 hour' },
  { value: '240', text: '4 hours' },
  { value: 'never', text: 'Never (until you lock it)' },
];

const form = useForm({
  vault_lock_timeout_minutes: props.vaultLockTimeoutMinutes === null ? 'never' : String(props.vaultLockTimeoutMinutes),
});

function submit(): void {
  form.transform((data) => ({
    vault_lock_timeout_minutes: data.vault_lock_timeout_minutes === 'never' ? null : Number(data.vault_lock_timeout_minutes),
  })).patch('/settings', {
    preserveScroll: true,
    onSuccess: () => form.defaults(),
  });
}
</script>

<template>
  <form class="mb-4" @submit.prevent="submit">
    <section>
      <h2 class="h5 mb-3">Vault auto-lock</h2>
      <p class="small text-muted">
        After this much time without activity, the vault locks itself and the key kept for page reloads is discarded.
        You stay signed in; you'll be asked for your master password the next time something needs the vault.
        The time left is shown in the header next to your account menu.
      </p>

      <BAlert :model-value="!!form.recentlySuccessful" variant="success" dismissible>Auto-lock updated.</BAlert>

      <BFormGroup label="Lock after" label-for="vault_lock_timeout_minutes" :state="form.errors.vault_lock_timeout_minutes ? false : null" :invalid-feedback="form.errors.vault_lock_timeout_minutes">
        <BFormSelect id="vault_lock_timeout_minutes" v-model="form.vault_lock_timeout_minutes" :options="OPTIONS" class="w-auto" />
      </BFormGroup>

      <div class="mt-3">
        <BButton type="submit" variant="primary" :disabled="form.processing">Save auto-lock</BButton>
      </div>
    </section>
  </form>
</template>
