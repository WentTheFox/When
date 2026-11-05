<script setup lang="ts">
/**
 * Settings.vue's "Share links" card — picks which custom fields on a
 * connection drive its share link: an identifier_list field for the highlight
 * tokens and a Yes/No field for bypassing do-not-disturb. Nothing selected
 * means nothing is highlighted / no link bypasses DND.
 *
 * Field labels are §0.1 E2EE, hence the vault gate; the values themselves are
 * §0.2 tier and never touch the vault.
 */
import { useForm } from '@inertiajs/vue3';
import { BButton, BCard, BFormGroup, BFormSelect } from 'bootstrap-vue-next';
import { computed, ref, watch } from 'vue';
import { decryptString } from '../crypto';
import { rawPreview } from './rawCiphertext';
import VaultGate from './VaultGate.vue';
import { useVault } from './useVault';

type Definition = { id: string; label_ciphertext: string };

const props = defineProps<{
  highlightDefinitions: Definition[];
  bypassDefinitions: Definition[];
  selectedHighlightId: string | null;
  selectedBypassId: string | null;
}>();

const { vaultUnlocked, getRecordKey } = useVault();

const labels = ref<Record<string, string>>({});
watch(vaultUnlocked, async (unlocked) => {
  if (!unlocked) return;
  for (const definition of [...props.highlightDefinitions, ...props.bypassDefinitions]) {
    try {
      labels.value[definition.id] = await decryptString(await getRecordKey(definition.id), definition.label_ciphertext);
    } catch (error) {
      console.error(error);
      labels.value[definition.id] = '(could not decrypt)';
    }
  }
}, { immediate: true });

function optionsFor(definitions: Definition[], noneText: string) {
  return [
    { value: '', text: noneText },
    ...definitions.map((d) => ({ value: d.id, text: labels.value[d.id] ?? rawPreview(d.label_ciphertext) })),
  ];
}

const highlightOptions = computed(() => optionsFor(props.highlightDefinitions, '(none — nothing is highlighted)'));
const bypassOptions = computed(() => optionsFor(props.bypassDefinitions, '(none — no link bypasses DND)'));

const form = useForm({
  highlight_identifier_definition_id: props.selectedHighlightId ?? '',
  bypass_dnd_definition_id: props.selectedBypassId ?? '',
});

function save(): void {
  form.transform((data) => ({
    highlight_identifier_definition_id: data.highlight_identifier_definition_id || null,
    bypass_dnd_definition_id: data.bypass_dnd_definition_id || null,
  })).patch('/settings', { preserveScroll: true, onSuccess: () => form.defaults() });
}
</script>

<template>
  <BCard id="share-links" class="mb-4">
    <h2 class="h5">Share links</h2>
    <p class="small text-muted">
      Each connection can have a share link (enable it when editing the connection). What that link highlights, and
      whether it bypasses do-not-disturb, comes from the fields below on <strong>that connection</strong>. With a field
      unselected — or a connection that has no value for it — nothing is highlighted and the link does not bypass DND.
    </p>

    <VaultGate compact>
      <form @submit.prevent="save">
        <div id="highlight-tokens" class="mb-3">
          <p v-if="!highlightDefinitions.length" class="small mb-0">
            No identifier list fields yet — add one under Connections → Custom attributes
            (type “Identifier list (not E2EE)”) to choose highlight tokens.
          </p>
          <BFormGroup v-else label="Highlight tokens field" style="max-width: 28rem;">
            <BFormSelect v-model="form.highlight_identifier_definition_id" :options="highlightOptions" size="sm" />
          </BFormGroup>
        </div>

        <div id="bypass-dnd" class="mb-3">
          <p v-if="!bypassDefinitions.length" class="small mb-0">
            No Yes/No fields yet — add one under Connections → Custom attributes (type “Yes/No (not E2EE)”)
            to let a link bypass do-not-disturb.
          </p>
          <BFormGroup v-else label="Bypass do-not-disturb field" style="max-width: 28rem;">
            <BFormSelect v-model="form.bypass_dnd_definition_id" :options="bypassOptions" size="sm" />
          </BFormGroup>
        </div>

        <BButton
          v-if="highlightDefinitions.length || bypassDefinitions.length"
          type="submit"
          size="sm"
          variant="primary"
          :disabled="form.processing || !form.isDirty"
        >
          Save
        </BButton>
      </form>
    </VaultGate>
  </BCard>
</template>
