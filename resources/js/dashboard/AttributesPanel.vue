<script setup lang="ts">
import { BButton, BCard, BFormCheckbox, BFormInput, BFormSelect } from 'bootstrap-vue-next';
import { ref } from 'vue';

interface DefinitionRow {
  id: string;
  label: string;
  type: string;
  options: string[];
  isE2ee: boolean;
  purpose: string | null;
}

defineProps<{ definitions: DefinitionRow[] }>();
const emit = defineEmits<{ add: [string, string, string[], boolean, string | null]; remove: [string] }>();

const newLabel = ref('');
const newType = ref('text');
const newChoices = ref('');
// Mirrors App\Support\CustomFieldPurpose::KEYS — kept in sync by hand, same
// relationship newType's own <option> list already has with
// ConnectionAttributeDefinitionController::store()'s 'in:' rule.
const newE2eeDisabled = ref(false);
const newPurpose = ref('');

function add(): void {
  if (!newLabel.value) return;
  const choices = newType.value === 'radio'
    ? newChoices.value.split(',').map((c) => c.trim()).filter((c) => c !== '')
    : [];
  emit('add', newLabel.value, newType.value, choices, !newE2eeDisabled.value, newE2eeDisabled.value ? (newPurpose.value || null) : null);
  newLabel.value = '';
  newChoices.value = '';
  newE2eeDisabled.value = false;
  newPurpose.value = '';
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
          <span v-if="!definition.isE2ee" class="badge text-bg-warning ms-1">
            not encrypted<template v-if="definition.purpose"> — {{ definition.purpose }}</template>
          </span>
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
    <BFormCheckbox v-model="newE2eeDisabled" class="small mb-1">
      Store this field's value in plaintext at rest, not end-to-end encrypted
    </BFormCheckbox>
    <p v-if="newE2eeDisabled" class="small text-muted mb-1">
      Use this for an identifier a future visitor-facing flow needs to read server-side (e.g. a Discord or VRChat
      username) — the server can decrypt it, so it's never as protected as every other field here. Cannot be changed
      after creation.
    </p>
    <BFormSelect v-if="newE2eeDisabled" v-model="newPurpose" size="sm" class="mb-1" style="max-width: 12rem;">
      <option value="">No specific purpose</option>
      <option value="discord">Discord username</option>
      <option value="vrchat">VRChat username</option>
    </BFormSelect>
  </BCard>
</template>
