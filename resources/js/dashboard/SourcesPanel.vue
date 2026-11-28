<script setup lang="ts">
import { BButton, BFormGroup, BFormInput, BFormSelect } from 'bootstrap-vue-next';
import { computed, ref, watch } from 'vue';
import EncryptedInput from '../Components/EncryptedInput.vue';
import EncryptedText from '../Components/EncryptedText.vue';
import SourceBadge from '../Components/SourceBadge.vue';
import SwatchDot from '../Components/SwatchDot.vue';
import { requestConfirm } from './confirmModal';
import ColorPicker from './ColorPicker.vue';
import IconPicker from './IconPicker.vue';
import LockedOverlay from './LockedOverlay.vue';
import { sourceLook } from './sourceLook';
import { swatchByKey } from '../free/color-palette';
import { useResolvedTheme } from '../composables/useTheme';

interface SourceOption {
  id: string;
  category_id: string | null;
  /** Raw E2EE name, shown until `label` has been decrypted. */
  name_ciphertext: string;
  label?: string;
  /** The source's own icon key (palette icon, `flag:xx` or `brand:xx`); null to use its category's. Undefined until decrypted. */
  icon?: string | null;
  /** The source's own palette color; null to use its category's. */
  color_key?: string | null;
}

interface CategoryOption {
  id: string;
  label: string;
  color_key?: string | null;
  /** The category's icon, inherited by its sources. */
  icon?: string | null;
}

const props = defineProps<{ sources: SourceOption[]; categories: CategoryOption[] }>();
const emit = defineEmits<{ add: [string]; update: [string, string, string | null, string | null, string | null]; remove: [string] }>();

/** What a source shows before its name: its own icon/color, else its category's. */
function lookOf(source: SourceOption) {
  return sourceLook(source, props.categories);
}

const newName = ref('');
/** The open record; bound by the page (v-model:selected-id) so it can live in the URL hash. */
const selectedId = defineModel<string | null>('selectedId', { default: null });
const editName = ref('');
const editCategoryId = ref<string | null>(null);
/** The open source's own overrides; null means "use the category's". */
const editIcon = ref<string | null>(null);
const editColorKey = ref<string | null>(null);
const theme = useResolvedTheme();
/** What the open source shows right now (its overrides over its category's), for the preview and the picker tint. */
const editLook = computed(() => sourceLook({ category_id: editCategoryId.value, icon: editIcon.value, color_key: editColorKey.value }, props.categories));
const editTint = computed(() => {
  const swatch = swatchByKey(editLook.value.color_key ?? undefined) ?? swatchByKey('blue');

  return swatch ? swatch[theme.value] : undefined;
});

// Lists render in the order they were given (their stored order, see listOrder.ts); sorting by name here would reshuffle them as names decrypt.
const filteredSources = computed(() => {
  const query = newName.value.trim().toLowerCase();
  if (!query) return props.sources;
  return props.sources.filter((s) => (s.label ?? '').toLowerCase().includes(query));
});

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
  editIcon.value = current?.icon ?? null;
  editColorKey.value = current?.color_key ?? null;
}, { immediate: true, deep: true });

function save(): void {
  if (!selectedId.value || !editName.value || pending.value) return;
  emit('update', selectedId.value, editName.value, editCategoryId.value, editIcon.value, editColorKey.value);
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
      <div class="col-md-4 wtf-master-column">
        <LockedOverlay>
          <div class="input-group input-group-sm mb-2">
            <BFormInput v-model="newName" type="text" placeholder="Search or new source" @keyup.enter="add" />
            <BButton variant="outline-secondary" @click="add">Add</BButton>
          </div>
          <p v-if="filteredSources.length === 0" class="text-muted small wtf-master-list">No sources found.</p>
          <div v-else class="list-group wtf-master-list">
            <button
              v-for="source in filteredSources"
              :key="source.id"
              type="button"
              class="list-group-item list-group-item-action"
              :class="{ active: selectedId === source.id }"
              @click="select(source.id)"
            >
              <SourceBadge v-if="lookOf(source).icon" :icon-key="lookOf(source).icon!" :color-key="lookOf(source).color_key" class="me-2" />
              <SwatchDot v-else :color-key="lookOf(source).color_key" class="me-2" />
              <EncryptedText :value="source.label" :ciphertext="source.name_ciphertext" />
            </button>
          </div>
        </LockedOverlay>
      </div>

      <div class="col-md-8">
        <LockedOverlay v-if="selected">
          <BFormGroup label="Name" class="mb-3">
            <EncryptedInput v-model="editName" :ciphertext="selected.name_ciphertext" :pending="pending" type="text" size="sm" />
          </BFormGroup>
          <BFormGroup label="Category" class="mb-3">
            <BFormSelect v-model="editCategoryId" size="sm" :disabled="pending">
              <option :value="null">(none)</option>
              <option v-for="category in categories" :key="category.id" :value="category.id">{{ category.label }}</option>
            </BFormSelect>
          </BFormGroup>
          <BFormGroup label="Icon" class="mb-3">
            <div class="d-flex flex-wrap align-items-center gap-2">
              <IconPicker v-model="editIcon" label="Icon for this source" with-flags :active-color="editTint" />
              <SourceBadge v-if="editLook.icon" :icon-key="editLook.icon" :color-key="editLook.color_key" />
              <BButton v-if="editIcon" size="sm" variant="outline-secondary" :disabled="pending" @click="editIcon = null">Use category's icon</BButton>
              <span v-else class="small text-muted">{{ editLook.icon ? "From the category" : "No icon" }}</span>
            </div>
          </BFormGroup>
          <BFormGroup label="Color" class="mb-3">
            <div class="d-flex flex-wrap align-items-center gap-2">
              <ColorPicker v-model="editColorKey" label="Source color" preview="solid" :disabled="pending" />
              <BButton v-if="editColorKey" size="sm" variant="outline-secondary" :disabled="pending" @click="editColorKey = null">Use category's color</BButton>
              <span v-else class="small text-muted">{{ editLook.color_key ? "From the category" : "No color" }}</span>
            </div>
          </BFormGroup>
          <BButton size="sm" variant="primary" :disabled="pending" @click="save">Save</BButton>
          <BButton size="sm" variant="outline-danger" class="ms-2" :disabled="pending" @click="remove">Delete</BButton>
        </LockedOverlay>
        <p v-else class="text-muted">Select a source on the left to rename or delete it.</p>
      </div>
    </div>
  </div>
</template>
