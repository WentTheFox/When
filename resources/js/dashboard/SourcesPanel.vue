<script setup lang="ts">
import { BButton, BCard, BFormInput } from 'bootstrap-vue-next';
import { ref } from 'vue';

defineProps<{ sources: { id: string; label: string }[] }>();
const emit = defineEmits<{ add: [string]; remove: [string] }>();

const newName = ref('');

function add(): void {
  if (!newName.value) return;
  emit('add', newName.value);
  newName.value = '';
}
</script>

<template>
  <BCard class="mb-3">
    <h2 class="h6">Sources</h2>
    <ul class="list-unstyled mb-2">
      <li v-for="source in sources" :key="source.id" class="d-flex justify-content-between">
        <span>{{ source.label }}</span>
        <button type="button" class="btn btn-link btn-sm p-0" @click="emit('remove', source.id)">&times;</button>
      </li>
    </ul>
    <div class="input-group input-group-sm">
      <BFormInput v-model="newName" type="text" placeholder="New source" @keyup.enter="add" />
      <BButton variant="outline-secondary" @click="add">Add</BButton>
    </div>
  </BCard>
</template>
