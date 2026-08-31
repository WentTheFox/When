<script setup lang="ts">
import { BButton, BCard, BFormGroup, BFormInput } from 'bootstrap-vue-next';
import { computed, ref, watch } from 'vue';

interface SourceOption {
  id: string;
  category_id: string | null;
  label: string;
}

const props = defineProps<{ sources: SourceOption[] }>();
const emit = defineEmits<{ add: [string]; update: [string, string]; remove: [string] }>();

const newName = ref('');
const selectedId = ref<string | null>(null);
const editName = ref('');

const sortedSources = computed(() => [...props.sources].sort((a, b) => a.label.localeCompare(b.label)));
const filteredSources = computed(() => {
  const query = newName.value.trim().toLowerCase();
  if (!query) return sortedSources.value;
  return sortedSources.value.filter((s) => s.label.toLowerCase().includes(query));
});

function add(): void {
  if (!newName.value) return;
  emit('add', newName.value);
  newName.value = '';
}

function select(id: string): void {
  selectedId.value = id;
  editName.value = props.sources.find((s) => s.id === id)?.label ?? '';
}

// Keeps the edit field in sync if this source's name changes from
// elsewhere (e.g. the emitted 'update' round-tripping back through props)
// without clobbering what's being typed if a *different* source updates.
watch(() => props.sources, (sources) => {
  if (!selectedId.value) return;
  const current = sources.find((s) => s.id === selectedId.value);
  if (current) editName.value = current.label;
}, { deep: true });

function save(): void {
  if (!selectedId.value || !editName.value) return;
  emit('update', selectedId.value, editName.value);
}

function remove(): void {
  if (!selectedId.value) return;
  if (!window.confirm('Delete this source?')) return;
  emit('remove', selectedId.value);
  selectedId.value = null;
}
</script>

<template>
  <BCard class="mb-4">
    <h2 class="h5 mb-3">Connection sources</h2>
    <div class="row">
      <div class="col-md-4">
        <div class="input-group input-group-sm mb-2">
          <BFormInput v-model="newName" type="text" placeholder="Search or new source" @keyup.enter="add" />
          <BButton variant="outline-secondary" @click="add">Add</BButton>
        </div>
        <p v-if="filteredSources.length === 0" class="text-muted small">No sources found.</p>
        <div v-else class="list-group wtf-master-list">
          <button
            v-for="source in filteredSources"
            :key="source.id"
            type="button"
            class="list-group-item list-group-item-action"
            :class="{ active: selectedId === source.id }"
            @click="select(source.id)"
          >
            {{ source.label }}
          </button>
        </div>
      </div>

      <div class="col-md-8">
        <template v-if="selectedId">
          <BFormGroup label="Name" class="mb-3">
            <BFormInput v-model="editName" type="text" size="sm" />
          </BFormGroup>
          <BButton size="sm" variant="primary" @click="save">Save</BButton>
          <BButton size="sm" variant="outline-danger" @click="remove">Delete</BButton>
        </template>
        <p v-else class="text-muted">Select a source on the left to rename or delete it.</p>
      </div>
    </div>
  </BCard>
</template>
