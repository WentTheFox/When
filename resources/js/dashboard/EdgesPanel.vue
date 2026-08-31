<script setup lang="ts">
import { BButton, BCard, BFormInput, BFormSelect } from 'bootstrap-vue-next';
import { ref } from 'vue';

defineProps<{
  edges: { id: string; fromLabel: string; toLabel: string; label: string }[];
  connections: { id: string; label: string }[];
}>();
const emit = defineEmits<{ add: [string, string, string]; remove: [string] }>();

const fromId = ref('');
const toId = ref('');
const label = ref('');

function add(): void {
  if (!fromId.value || !toId.value || fromId.value === toId.value) return;
  emit('add', fromId.value, toId.value, label.value);
  label.value = '';
}
</script>

<template>
  <BCard class="mb-3">
    <h2 class="h6">Relationships</h2>
    <p class="small text-muted">A simple list — no graph view yet.</p>
    <ul class="list-unstyled mb-2">
      <li v-for="edge in edges" :key="edge.id" class="d-flex justify-content-between align-items-center">
        <span>{{ edge.fromLabel }} &rarr; {{ edge.toLabel }}<template v-if="edge.label"> ({{ edge.label }})</template></span>
        <button type="button" class="btn btn-link btn-sm p-0" @click="emit('remove', edge.id)">&times;</button>
      </li>
    </ul>
    <div class="mb-3">
      <BFormSelect v-model="fromId" size="sm" class="mb-1">
        <option value="" disabled>From&hellip;</option>
        <option v-for="c in connections" :key="c.id" :value="c.id">{{ c.label }}</option>
      </BFormSelect>
      <BFormSelect v-model="toId" size="sm" class="mb-1">
        <option value="" disabled>To&hellip;</option>
        <option v-for="c in connections" :key="c.id" :value="c.id">{{ c.label }}</option>
      </BFormSelect>
      <BFormInput v-model="label" type="text" size="sm" class="mb-1" placeholder="Label (e.g. sibling of)" />
      <BButton variant="outline-secondary" size="sm" class="w-100" @click="add">Add relationship</BButton>
    </div>
  </BCard>
</template>
