<script setup lang="ts">
import { BButton, BCard, BFormInput, BFormSelect } from 'bootstrap-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{
  definitions: { id: string; label: string; type: string; options: string[] }[];
  /** Null while idle; otherwise the bulk identifier copy's progress/result line. */
  copyStatus: string | null;
  copyBusy: boolean;
}>();
const emit = defineEmits<{ add: [string, string, string[]]; remove: [string]; copyToIdentifier: [string, string] }>();

const textDefinitions = computed(() => props.definitions.filter((d) => d.type === 'text'));
const identifierDefinitions = computed(() => props.definitions.filter((d) => d.type === 'identifier'));
const copySourceId = ref('');
const copyTargetId = ref('');

const newLabel = ref('');
const newType = ref('text');
const newChoices = ref('');

function add(): void {
  if (!newLabel.value) return;
  const choices = newType.value === 'radio'
    ? newChoices.value.split(',').map((c) => c.trim()).filter((c) => c !== '')
    : [];
  emit('add', newLabel.value, newType.value, choices);
  newLabel.value = '';
  newChoices.value = '';
}
</script>

<template>
  <BCard class="mb-3">
    <h2 class="h6">Custom attributes</h2>
    <ul class="list-unstyled mb-2">
      <li v-for="definition in definitions" :key="definition.id" class="d-flex justify-content-between">
        <span>
          {{ definition.label }} <span class="text-muted">({{ definition.type }})</span>
          <span v-if="definition.type === 'radio'" class="text-muted small"> — {{ definition.options.join(', ') }}</span>
        </span>
        <button type="button" class="btn btn-link btn-sm p-0" @click="emit('remove', definition.id)">&times;</button>
      </li>
    </ul>
    <div class="input-group input-group-sm mb-1">
      <BFormInput v-model="newLabel" type="text" placeholder="Label" @keyup.enter="add" />
      <BFormSelect v-model="newType" style="max-width: 8rem;">
        <option value="text">Text</option>
        <option value="textarea">Multi-line</option>
        <option value="radio">Choice</option>
        <option value="date">Date</option>
        <option value="number">Number</option>
        <option value="url">URL</option>
        <option value="email">Email</option>
        <option value="phone">Phone</option>
        <option value="identifier">Identifier (not E2EE)</option>
      </BFormSelect>
      <BButton variant="outline-secondary" @click="add">Add</BButton>
    </div>
    <BFormInput
      v-if="newType === 'radio'"
      v-model="newChoices"
      type="text"
      size="sm"
      class="mb-1"
      placeholder="Choices, comma-separated"
      @keyup.enter="add"
    />
    <p v-if="newType === 'identifier'" class="small text-muted mb-1">
      Identifier values are <strong>not</strong> end-to-end encrypted: they're sent to the server in plaintext
      (and encrypted at rest with the app key) so they can be used to link a connection to an external service.
    </p>

    <template v-if="textDefinitions.length && identifierDefinitions.length">
      <hr>
      <h3 class="h6">Copy a text field into an identifier</h3>
      <p class="small text-muted mb-2">
        Decrypts the chosen text field in your browser and sends each value to the server in plaintext, for every
        connection whose identifier is still empty. Existing identifiers are never overwritten, so it's safe to re-run.
      </p>
      <div class="input-group input-group-sm mb-1">
        <BFormSelect v-model="copySourceId">
          <option value="" disabled>From (text)…</option>
          <option v-for="d in textDefinitions" :key="d.id" :value="d.id">{{ d.label }}</option>
        </BFormSelect>
        <BFormSelect v-model="copyTargetId">
          <option value="" disabled>To (identifier)…</option>
          <option v-for="d in identifierDefinitions" :key="d.id" :value="d.id">{{ d.label }}</option>
        </BFormSelect>
        <BButton
          variant="outline-secondary"
          :disabled="copyBusy || !copySourceId || !copyTargetId"
          @click="emit('copyToIdentifier', copySourceId, copyTargetId)"
        >
          Copy
        </BButton>
      </div>
      <div v-if="copyStatus" class="small text-muted">{{ copyStatus }}</div>
    </template>
  </BCard>
</template>
