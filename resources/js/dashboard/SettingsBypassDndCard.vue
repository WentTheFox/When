<script setup lang="ts">
/**
 * Settings.vue's "Bypass do not disturb" card — the boolean-field twin of
 * SettingsHighlightTokensCard.vue: picks which Yes/No custom field on the
 * tied connection decides whether a share link bypasses DND (unset = each
 * link's own switch), and can copy every link's current switch onto its
 * connection's field first (POST /settings/bypass-dnd/migrate, which selects
 * the field afterwards). Labels are §0.1 E2EE (vault-gated); the values are §0.2.
 */
import { router, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { BAlert, BButton, BCard, BFormGroup, BFormSelect } from 'bootstrap-vue-next';
import { computed, ref, watch } from 'vue';
import { decryptString } from '../crypto';
import VaultGate from './VaultGate.vue';
import { useVault } from './useVault';

const props = defineProps<{
  selectedDefinitionId: string | null;
  definitions: { id: string; label_ciphertext: string }[];
  shareLinkCount: number;
}>();

const { vaultUnlocked, getRecordKey } = useVault();

const labels = ref<Record<string, string>>({});
watch(vaultUnlocked, async (unlocked) => {
  if (!unlocked) return;
  for (const definition of props.definitions) {
    try {
      labels.value[definition.id] = await decryptString(await getRecordKey(definition.id), definition.label_ciphertext);
    } catch (error) {
      console.error(error);
      labels.value[definition.id] = '(could not decrypt)';
    }
  }
}, { immediate: true });

const options = computed(() => [
  { value: '', text: '(none — no link bypasses DND)' },
  ...props.definitions.map((d) => ({ value: d.id, text: labels.value[d.id] ?? '…' })),
]);

const form = useForm({ bypass_dnd_definition_id: props.selectedDefinitionId ?? '' });

function save(): void {
  form.transform((data) => ({ bypass_dnd_definition_id: data.bypass_dnd_definition_id || null }))
    .patch('/settings', { preserveScroll: true, onSuccess: () => form.defaults() });
}

const migrateTargetId = ref(props.selectedDefinitionId ?? props.definitions[0]?.id ?? '');
const migrating = ref(false);
const migrateResult = ref<string | null>(null);
const migrateError = ref(false);

async function migrate(): Promise<void> {
  migrating.value = true;
  migrateError.value = false;
  try {
    const { data } = await axios.post('/settings/bypass-dnd/migrate', { definition_id: migrateTargetId.value });
    const skipped: string[] = [];
    if (data.skipped_no_connection) skipped.push(`${data.skipped_no_connection} without a tied connection (not carried over — tie a connection and re-run)`);
    if (data.skipped_existing) skipped.push(`${data.skipped_existing} whose connection already had a value (left untouched)`);
    migrateResult.value = `Copied the switch of ${data.migrated} share link${data.migrated === 1 ? '' : 's'}`
      + (skipped.length ? `; skipped ${skipped.join(' and ')}` : '')
      + '. This field is now your bypass-DND source.';
    router.reload({ only: ['settings'] });
    form.bypass_dnd_definition_id = migrateTargetId.value;
    form.defaults();
  } catch (error) {
    console.error(error);
    migrateError.value = true;
    migrateResult.value = 'Migration failed — it is safe to re-run.';
  } finally {
    migrating.value = false;
  }
}
</script>

<template>
  <BCard id="bypass-dnd" class="mb-4">
    <h2 class="h5">Bypass do not disturb</h2>
    <p class="small text-muted">
      Each share link's bypass-DND setting comes from a <strong>Yes/No</strong> field on the connection it's tied to.
      With no field selected — or for a link with no tied connection or no value — <strong>no link bypasses DND</strong>.
    </p>

    <VaultGate compact>
      <p v-if="!definitions.length" class="small mb-0">
        No Yes/No fields yet — add one under Connections → Custom attributes (type “Yes/No (not E2EE)”).
      </p>
      <template v-else>
        <form @submit.prevent="save">
          <BFormGroup label="Bypass DND field" class="mb-2" style="max-width: 28rem;">
            <BFormSelect v-model="form.bypass_dnd_definition_id" :options="options" size="sm" />
          </BFormGroup>
          <BButton type="submit" size="sm" variant="primary" :disabled="form.processing || !form.isDirty">Save</BButton>
        </form>

        <template v-if="shareLinkCount > 0">
          <hr>
          <h3 class="h6">Copy existing switches to a field</h3>
          <p class="small text-muted mb-2">
            Copies each tied link's current switch (on or off) onto its connection's field below — never overwriting a
            value that's already set — then selects that field above. Links without a tied connection can't be copied (tie one first, then re-run). The links' own switches are kept untouched but are no longer used.
          </p>
          <div class="input-group input-group-sm" style="max-width: 28rem;">
            <BFormSelect v-model="migrateTargetId" size="sm">
              <option v-for="d in definitions" :key="d.id" :value="d.id">{{ labels[d.id] ?? '…' }}</option>
            </BFormSelect>
            <BButton variant="outline-secondary" :disabled="migrating || !migrateTargetId" @click="migrate">
              Copy &amp; use this field
            </BButton>
          </div>
        </template>
        <BAlert :model-value="!!migrateResult" :variant="migrateError ? 'danger' : 'success'" class="small mt-2 mb-0">
          {{ migrateResult }}
        </BAlert>
      </template>
    </VaultGate>
  </BCard>
</template>
