<script setup lang="ts">
/**
 * Settings.vue's "Connections" card — for now, picks the custom field that lists a
 * connection's other names (previous names, nicknames). The connections search then
 * finds a connection by any of them, and shows which one matched.
 *
 * Either an identifier list or a multi-line field (one name per line) works. Field labels
 * are §0.1 E2EE, hence the vault gate; the server only remembers which field was chosen and
 * never reads the names, so this adds nothing server-side to protect.
 */
import { useForm } from '@inertiajs/vue3';
import { BButton, BFormGroup, BFormSelect } from 'bootstrap-vue-next';
import { computed, ref, watch } from 'vue';
import { decryptString } from '../crypto';
import { rawPreview } from './rawCiphertext';
import VaultGate from './VaultGate.vue';
import { useVault } from './useVault';

type Definition = { id: string; label_ciphertext: string; type: string };

const props = defineProps<{
  definitions: Definition[];
  selectedId: string | null;
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
  { value: '', text: '(none — search by name only)' },
  ...props.definitions.map((d) => ({
    value: d.id,
    text: `${labels.value[d.id] ?? rawPreview(d.label_ciphertext)} (${d.type === 'identifier_list' ? 'identifier list' : 'multi-line'})`,
  })),
]);

const form = useForm({ alternative_names_definition_id: props.selectedId ?? '' });

function save(): void {
  form.transform((data) => ({
    alternative_names_definition_id: data.alternative_names_definition_id || null,
  })).patch('/settings', { preserveScroll: true, onSuccess: () => form.defaults() });
}
</script>

<template>
  <section id="connections">
    <h2 class="h5 mb-3">Connections</h2>

    <VaultGate compact>
      <form @submit.prevent="save">
        <div id="alternative-names" class="mb-3">
          <p class="small text-muted">
            Pick the field where you keep a connection's other names, such as previous names or nicknames. Searching
            the connections list then finds a connection by any of them, and shows which one matched.
          </p>
          <p v-if="!definitions.length" class="small mb-0">
            No suitable fields yet — add one under Connections → Custom attributes, of type “Identifier list (not E2EE)”
            or “Multi-line” (one name per line).
          </p>
          <BFormGroup v-else label="Alternative names field" style="max-width: 28rem;">
            <BFormSelect v-model="form.alternative_names_definition_id" :options="options" size="sm" />
          </BFormGroup>
        </div>

        <BButton v-if="definitions.length" type="submit" size="sm" variant="primary" :disabled="form.processing || !form.isDirty">
          Save
        </BButton>
      </form>
    </VaultGate>
  </section>
</template>
