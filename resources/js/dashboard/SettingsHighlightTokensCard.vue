<script setup lang="ts">
/**
 * Settings.vue's "Highlight tokens" card — picks which identifier_list custom
 * field (Connections → Custom attributes) supplies each share link's highlight
 * tokens, via the tied connection. Unset = the legacy per-link words. Also
 * hosts the one-click migration of those legacy words onto a chosen field
 * (POST /settings/highlight-words/migrate, which selects the field afterwards).
 *
 * Field labels are §0.1 E2EE, hence the vault gate; the tokens themselves are
 * §0.2 tier and never touch the vault.
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
  legacyLinkCount: number;
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
  { value: '', text: '(none — nothing is highlighted)' },
  ...props.definitions.map((d) => ({ value: d.id, text: labels.value[d.id] ?? '…' })),
]);

const form = useForm({ highlight_identifier_definition_id: props.selectedDefinitionId ?? '' });

function save(): void {
  form.transform((data) => ({ highlight_identifier_definition_id: data.highlight_identifier_definition_id || null }))
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
    const { data } = await axios.post('/settings/highlight-words/migrate', { definition_id: migrateTargetId.value });
    const skipped: string[] = [];
    if (data.skipped_no_connection) skipped.push(`${data.skipped_no_connection} without a tied connection (their words are not carried over — tie a connection and re-run)`);
    if (data.skipped_existing) skipped.push(`${data.skipped_existing} whose connection already had a list (left untouched)`);
    migrateResult.value = `Moved the words of ${data.migrated} share link${data.migrated === 1 ? '' : 's'}`
      + (skipped.length ? `; skipped ${skipped.join(' and ')}` : '')
      + '. This field is now your highlight source.';
    router.reload({ only: ['settings', 'legacyHighlightLinkCount'] });
    form.highlight_identifier_definition_id = migrateTargetId.value;
    form.defaults();
  } catch (error) {
    console.error(error);
    migrateError.value = true;
    migrateResult.value = 'Migration failed — nothing was changed that you can\'t safely re-run.';
  } finally {
    migrating.value = false;
  }
}
</script>

<template>
  <BCard id="highlight-tokens" class="mb-4">
    <h2 class="h5">Highlight tokens</h2>
    <p class="small text-muted">
      Each share link highlights events matching its tokens, taken from an <strong>identifier list</strong> field on the
      connection the link is tied to. With no field selected — or for a link with no tied connection or no value —
      <strong>nothing is highlighted</strong>.
    </p>

    <VaultGate compact>
      <p v-if="!definitions.length" class="small mb-0">
        No identifier list fields yet — add one under Connections → Custom attributes
        (type “Identifier list (not E2EE)”).
      </p>
      <template v-else>
        <form @submit.prevent="save">
          <BFormGroup label="Highlight tokens field" class="mb-2" style="max-width: 28rem;">
            <BFormSelect v-model="form.highlight_identifier_definition_id" :options="options" size="sm" />
          </BFormGroup>
          <BButton type="submit" size="sm" variant="primary" :disabled="form.processing || !form.isDirty">Save</BButton>
        </form>

        <template v-if="legacyLinkCount > 0">
          <hr>
          <h3 class="h6">Move existing words to a field</h3>
          <p class="small text-muted mb-2">
            {{ legacyLinkCount }} share link{{ legacyLinkCount === 1 ? ' has' : 's have' }} highlight words of
            their own. This copies each one onto its tied connection's list for the field below (never overwriting a
            list that's already filled in), then selects that field above. Links without a tied connection can't be moved (tie one first, then re-run). The original words are kept untouched but are no longer used.
          </p>
          <div class="input-group input-group-sm" style="max-width: 28rem;">
            <BFormSelect v-model="migrateTargetId" size="sm">
              <option v-for="d in definitions" :key="d.id" :value="d.id">{{ labels[d.id] ?? '…' }}</option>
            </BFormSelect>
            <BButton variant="outline-secondary" :disabled="migrating || !migrateTargetId" @click="migrate">
              Move &amp; use this field
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
