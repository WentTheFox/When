<script setup lang="ts">
import { BButton, BCard, BFormGroup, BFormInput, BFormSelect } from 'bootstrap-vue-next';
import { computed, ref, watch } from 'vue';

interface SourceOption {
  id: string;
  category_id: string | null;
  label: string;
}

interface CategoryOption {
  id: string;
  label: string;
}

const props = defineProps<{ sources: SourceOption[]; categories: CategoryOption[] }>();
const emit = defineEmits<{ add: [string]; update: [string, string, string | null]; remove: [string] }>();

const newName = ref('');
const selectedId = ref<string | null>(null);
const editName = ref('');
const editCategoryId = ref<string | null>(null);

const sortedSources = computed(() => [...props.sources].sort((a, b) => a.label.localeCompare(b.label)));
const filteredSources = computed(() => {
  const query = newName.value.trim().toLowerCase();
  if (!query) return sortedSources.value;
  return sortedSources.value.filter((s) => s.label.toLowerCase().includes(query));
});
const sortedCategories = computed(() => [...props.categories].sort((a, b) => a.label.localeCompare(b.label)));

function add(): void {
  if (!newName.value) return;
  emit('add', newName.value);
  newName.value = '';
}

function select(id: string): void {
  selectedId.value = id;
  const source = props.sources.find((s) => s.id === id);
  editName.value = source?.label ?? '';
  editCategoryId.value = source?.category_id ?? null;
}

// Keeps the edit fields in sync if this source's data changes from
// elsewhere (e.g. the emitted 'update' round-tripping back through props)
// without clobbering what's being typed if a *different* source updates.
watch(() => props.sources, (sources) => {
  if (!selectedId.value) return;
  const current = sources.find((s) => s.id === selectedId.value);
  if (current) {
    editName.value = current.label;
    editCategoryId.value = current.category_id;
  }
}, { deep: true });

function save(): void {
  if (!selectedId.value || !editName.value) return;
  emit('update', selectedId.value, editName.value, editCategoryId.value);
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
          <BFormGroup label="Category" class="mb-3">
            <BFormSelect v-model="editCategoryId" size="sm">
              <option :value="null">(none)</option>
              <option v-for="category in sortedCategories" :key="category.id" :value="category.id">{{ category.label }}</option>
            </BFormSelect>
          </BFormGroup>
          <BButton size="sm" variant="primary" @click="save">Save</BButton>
          <BButton size="sm" variant="outline-danger" @click="remove">Delete</BButton>
        </template>
        <p v-else class="text-muted">Select a source on the left to rename or delete it.</p>
      </div>
    </div>
  </BCard>
</template>
