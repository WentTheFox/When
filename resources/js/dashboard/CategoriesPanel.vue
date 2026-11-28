<script setup lang="ts">
import { BButton, BFormGroup, BFormInput } from 'bootstrap-vue-next';
import { computed, ref, watch } from 'vue';
import EncryptedInput from '../Components/EncryptedInput.vue';
import EncryptedText from '../Components/EncryptedText.vue';
import { requestConfirm } from './confirmModal';
import LockedOverlay from './LockedOverlay.vue';
import SourceBadge from '../Components/SourceBadge.vue';
import SwatchDot from '../Components/SwatchDot.vue';
import ColorPicker from './ColorPicker.vue';
import IconPicker from './IconPicker.vue';
import { swatchByKey } from '../free/color-palette';
import { useResolvedTheme } from '../composables/useTheme';

interface CategoryOption {
  id: string;
  color_key: string | null;
  /** Raw E2EE name, shown until `label` has been decrypted. */
  name_ciphertext: string;
  label?: string;
  /** The decrypted icon key (palette icon, `flag:xx` or `brand:xx`); null for none. */
  icon?: string | null;
}

const props = defineProps<{ categories: CategoryOption[] }>();
const emit = defineEmits<{
  add: [string];
  update: [string, string, string | null, string | null];
  remove: [string];
}>();

const newName = ref('');
/** The open record; bound by the page (v-model:selected-id) so it can live in the URL hash. */
const selectedId = defineModel<string | null>('selectedId', { default: null });
const editName = ref('');
const editColorKey = ref<string | null>(null);
const editIcon = ref<string | null>(null);
const theme = useResolvedTheme();
const editTint = computed(() => {
  const swatch = swatchByKey(editColorKey.value ?? undefined) ?? swatchByKey('blue');

  return swatch ? swatch[theme.value] : undefined;
});

// Lists render in the order they were given (their stored order, see listOrder.ts); sorting by name here would reshuffle them as names decrypt.
const filteredCategories = computed(() => {
  const query = newName.value.trim().toLowerCase();
  if (!query) return props.categories;
  return props.categories.filter((c) => (c.label ?? '').toLowerCase().includes(query));
});

function add(): void {
  if (!newName.value) return;
  emit('add', newName.value);
  newName.value = '';
}

function select(id: string): void {
  selectedId.value = id;
}

const selected = computed(() => props.categories.find((c) => c.id === selectedId.value) ?? null);
/** True until the open record's name has been decrypted (e.g. opened from a deep link before unlocking). */
const pending = computed(() => selected.value !== null && selected.value.label === undefined);

// Same reasoning as SourcesPanel.vue's equivalent watcher.
watch(selected, (current) => {
  editName.value = current?.label ?? '';
  editColorKey.value = current?.color_key ?? null;
  editIcon.value = current?.icon ?? null;
}, { immediate: true, deep: true });

function save(): void {
  if (!selectedId.value || !editName.value || pending.value) return;
  emit('update', selectedId.value, editName.value, editColorKey.value, editIcon.value);
}

async function remove(): Promise<void> {
  if (!selectedId.value || pending.value) return;

  const confirmed = await requestConfirm({
    title: 'Delete this category?',
    message: 'Sources using it keep their color unassigned.',
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
    <p class="small text-muted">
      Give a category a color and an icon: its sources show them as their logo and on the dashboard's connections graph, unless a source sets its own.
    </p>
    <div class="row">
      <div class="col-md-4 wtf-master-column">
        <LockedOverlay>
          <div class="input-group input-group-sm mb-2">
            <BFormInput v-model="newName" type="text" placeholder="Search or new category" @keyup.enter="add" />
            <BButton variant="outline-secondary" @click="add">Add</BButton>
          </div>
          <p v-if="filteredCategories.length === 0" class="text-muted small wtf-master-list">No categories found.</p>
          <div v-else class="list-group wtf-master-list">
            <button
              v-for="category in filteredCategories"
              :key="category.id"
              type="button"
              class="list-group-item list-group-item-action d-flex align-items-center gap-2"
              :class="{ active: selectedId === category.id }"
              @click="select(category.id)"
            >
              <SourceBadge v-if="category.icon" :icon-key="category.icon" :color-key="category.color_key" />
              <SwatchDot v-else :color-key="category.color_key" />
              <EncryptedText :value="category.label" :ciphertext="category.name_ciphertext" />
            </button>
          </div>
        </LockedOverlay>
      </div>

      <div class="col-md-8">
        <LockedOverlay v-if="selected">
          <BFormGroup label="Name" class="mb-3">
            <EncryptedInput v-model="editName" :ciphertext="selected.name_ciphertext" :pending="pending" type="text" size="sm" />
          </BFormGroup>
          <BFormGroup label="Color" class="mb-3">
            <ColorPicker v-model="editColorKey" label="Category color" preview="solid" :disabled="pending" />
          </BFormGroup>
          <BFormGroup label="Icon" class="mb-3">
            <div class="d-flex flex-wrap align-items-center gap-2">
              <IconPicker v-model="editIcon" label="Category icon" with-flags :active-color="editTint" />
              <SourceBadge v-if="editIcon" :icon-key="editIcon" :color-key="editColorKey" />
              <BButton v-if="editIcon" size="sm" variant="outline-secondary" :disabled="pending" @click="editIcon = null">Remove icon</BButton>
            </div>
          </BFormGroup>
          <BButton size="sm" variant="primary" :disabled="pending" @click="save">Save</BButton>
          <BButton size="sm" variant="outline-danger" class="ms-2" :disabled="pending" @click="remove">Delete</BButton>
        </LockedOverlay>
        <p v-else class="text-muted">Select a category on the left to rename, recolor, or delete it.</p>
      </div>
    </div>
  </div>
</template>
