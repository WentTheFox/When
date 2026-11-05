<script setup lang="ts">
import { BButton, BFormGroup, BFormInput, BFormSelect } from 'bootstrap-vue-next';
import { computed, ref, watch } from 'vue';
import EncryptedInput from '../Components/EncryptedInput.vue';
import EncryptedText from '../Components/EncryptedText.vue';
import { requestConfirm } from './confirmModal';
import LockedOverlay from './LockedOverlay.vue';

interface SourceOption {
  id: string;
  category_id: string | null;
  /** Raw E2EE name, shown until `label` has been decrypted. */
  name_ciphertext: string;
  label?: string;
}

interface CategoryOption {
  id: string;
  label: string;
}

const props = defineProps<{ sources: SourceOption[]; categories: CategoryOption[] }>();
const emit = defineEmits<{ add: [string]; update: [string, string, string | null]; remove: [string] }>();

const newName = ref('');
/** The open record; bound by the page (v-model:selected-id) so it can live in the URL hash. */
const selectedId = defineModel<string | null>('selectedId', { default: null });
const editName = ref('');
const editCategoryId = ref<string | null>(null);

const sortedSources = computed(() => [...props.sources].sort((a, b) => (a.label ?? '').localeCompare(b.label ?? '')));
const filteredSources = computed(() => {
  const query = newName.value.trim().toLowerCase();
  if (!query) return sortedSources.value;
  return sortedSources.value.filter((s) => (s.label ?? '').toLowerCase().includes(query));
});
const sortedCategories = computed(() => [...props.categories].sort((a, b) => a.label.localeCompare(b.label)));

function add(): void {
  if (!newName.value) return;
  emit('add', newName.value);
  newName.value = '';
}

function select(id: string): void {
  selectedId.value = id;
}

const selected = computed(() => props.sources.find((s) => s.id === selectedId.value) ?? null);
/** True until the open record's name has been decrypted (e.g. opened from a deep link before unlocking). */
const pending = computed(() => selected.value !== null && selected.value.label === undefined);

// Fill the edit fields whenever the open record changes or its data does (the
// name arriving once decrypted, or an 'update' round-tripping back through
// props). While pending they stay empty and the name field shows the raw
// ciphertext instead, disabled.
watch(selected, (current) => {
  editName.value = current?.label ?? '';
  editCategoryId.value = current?.category_id ?? null;
}, { immediate: true, deep: true });

function save(): void {
  if (!selectedId.value || !editName.value || pending.value) return;
  emit('update', selectedId.value, editName.value, editCategoryId.value);
}

async function remove(): Promise<void> {
  if (!selectedId.value || pending.value) return;

  const confirmed = await requestConfirm({
    title: 'Delete this source?',
    confirmText: 'Delete',
    variant: 'danger',
  });
  if (!confirmed) return;

  emit('remove', selectedId.value);
  selectedId.value = null;
}
</script>

<template>
  <div>
    <div class="row">
      <div class="col-md-4">
        <LockedOverlay>
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
              <EncryptedText :value="source.label" :ciphertext="source.name_ciphertext" />
            </button>
          </div>
        </LockedOverlay>
      </div>

      <div class="col-md-8">
        <LockedOverlay>
          <template v-if="selected">
            <BFormGroup label="Name" class="mb-3">
              <EncryptedInput v-model="editName" :ciphertext="selected.name_ciphertext" :pending="pending" type="text" size="sm" />
            </BFormGroup>
            <BFormGroup label="Category" class="mb-3">
              <BFormSelect v-model="editCategoryId" size="sm" :disabled="pending">
                <option :value="null">(none)</option>
                <option v-for="category in sortedCategories" :key="category.id" :value="category.id">{{ category.label }}</option>
              </BFormSelect>
            </BFormGroup>
            <BButton size="sm" variant="primary" :disabled="pending" @click="save">Save</BButton>
            <BButton size="sm" variant="outline-danger" :disabled="pending" @click="remove">Delete</BButton>
          </template>
          <p v-else class="text-muted">Select a source on the left to rename or delete it.</p>
        </LockedOverlay>
      </div>
    </div>
  </div>
</template>
