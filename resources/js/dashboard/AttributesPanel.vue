<script setup lang="ts">
import { BButton, BCard, BFormInput, BFormSelect } from 'bootstrap-vue-next';
import { ref } from 'vue';

defineProps<{ definitions: { id: string; label: string; type: string; options: string[] }[] }>();
const emit = defineEmits<{ add: [string, string, string[]]; remove: [string] }>();

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
  </BCard>
</template>
