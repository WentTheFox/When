<script setup lang="ts">
import { BButton, BFormGroup, BFormInput, BTooltip } from 'bootstrap-vue-next';
import { computed, ref, watch } from 'vue';
import EncryptedInput from '../Components/EncryptedInput.vue';
import EncryptedText from '../Components/EncryptedText.vue';
import { requestConfirm } from './confirmModal';
import LockedOverlay from './LockedOverlay.vue';
import { useResolvedTheme } from '../composables/useTheme';
import { getColorPalette, swatchByKey } from '../free/color-palette';

interface CategoryOption {
  id: string;
  color_key: string | null;
  /** Raw E2EE name, shown until `label` has been decrypted. */
  name_ciphertext: string;
  label?: string;
}

const props = defineProps<{ categories: CategoryOption[] }>();
const emit = defineEmits<{
  add: [string];
  update: [string, string, string | null];
  remove: [string];
}>();

const colorPalette = getColorPalette();
const resolvedTheme = useResolvedTheme();

function dotHex(colorKey: string | null): string | undefined {
  const swatch = swatchByKey(colorKey ?? undefined);
  if (!swatch) return undefined;
  return resolvedTheme.value === 'dark' ? swatch.dark : swatch.light;
}

const newName = ref('');
/** The open record; bound by the page (v-model:selected-id) so it can live in the URL hash. */
const selectedId = defineModel<string | null>('selectedId', { default: null });
const editName = ref('');
const editColorKey = ref<string | null>(null);

const sortedCategories = computed(() => [...props.categories].sort((a, b) => (a.label ?? '').localeCompare(b.label ?? '')));
const filteredCategories = computed(() => {
  const query = newName.value.trim().toLowerCase();
  if (!query) return sortedCategories.value;
  return sortedCategories.value.filter((c) => (c.label ?? '').toLowerCase().includes(query));
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
}, { immediate: true, deep: true });

function save(): void {
  if (!selectedId.value || !editName.value || pending.value) return;
  emit('update', selectedId.value, editName.value, editColorKey.value);
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

/**
 * One shared tooltip for every swatch in this grid, not a per-swatch
 * v-b-tooltip instance — same reasoning as Settings.vue's own color
 * pickers (see CLAUDE.md's swatch-grid gotcha).
 */
const tooltipVisible = ref(false);
const activeSwatchTarget = ref<HTMLElement | null>(null);
const activeSwatchLabel = ref('');

function showSwatchTooltip(event: FocusEvent | MouseEvent, label: string): void {
  activeSwatchTarget.value = event.currentTarget as HTMLElement;
  activeSwatchLabel.value = label;
  tooltipVisible.value = true;
}

function hideSwatchTooltip(): void {
  tooltipVisible.value = false;
}
</script>

<template>
  <div>
    <p class="small text-muted">
      Give a category a color to distinguish its sources on the dashboard's connections graph.
    </p>
    <div class="row">
      <div class="col-md-4">
        <LockedOverlay>
          <div class="input-group input-group-sm mb-2">
            <BFormInput v-model="newName" type="text" placeholder="Search or new category" @keyup.enter="add" />
            <BButton variant="outline-secondary" @click="add">Add</BButton>
          </div>
          <p v-if="filteredCategories.length === 0" class="text-muted small">No categories found.</p>
          <div v-else class="list-group wtf-master-list">
            <button
              v-for="category in filteredCategories"
              :key="category.id"
              type="button"
              class="list-group-item list-group-item-action d-flex align-items-center gap-2"
              :class="{ active: selectedId === category.id }"
              @click="select(category.id)"
            >
              <span
                v-if="category.color_key"
                class="wtf-swatch-dot"
                :style="{ background: dotHex(category.color_key) }"
              ></span>
              <EncryptedText :value="category.label" :ciphertext="category.name_ciphertext" />
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
          <BFormGroup label="Color" class="mb-3">
            <div class="wtf-swatch-grid">
              <button
                v-for="swatch in colorPalette"
                :key="swatch.key"
                type="button"
                class="wtf-swatch-btn"
                :class="{ 'wtf-swatch-btn-active': editColorKey === swatch.key }"
                :aria-pressed="editColorKey === swatch.key"
                :disabled="pending"
                :style="{ '--app-swatch-light': swatch.light, '--app-swatch-dark': swatch.dark }"
                @click="editColorKey = editColorKey === swatch.key ? null : swatch.key"
                @mouseenter="showSwatchTooltip($event, swatch.label)"
                @mouseleave="hideSwatchTooltip"
                @focus="showSwatchTooltip($event, swatch.label)"
                @blur="hideSwatchTooltip"
              >
                <span class="visually-hidden">{{ swatch.label }}</span>
              </button>
            </div>
          </BFormGroup>
          <BButton size="sm" variant="primary" :disabled="pending" @click="save">Save</BButton>
          <BButton size="sm" variant="outline-danger" :disabled="pending" @click="remove">Delete</BButton>
        </template>
        <p v-else class="text-muted">Select a category on the left to rename, recolor, or delete it.</p>
        </LockedOverlay>
      </div>
    </div>

    <BTooltip
      v-if="activeSwatchTarget"
      v-model="tooltipVisible"
      :target="activeSwatchTarget"
      no-fade
      noninteractive
      placement="top"
    >
      {{ activeSwatchLabel }}
    </BTooltip>
  </div>
</template>

<style scoped>
.wtf-swatch-dot {
  display: inline-block;
  width: 0.75rem;
  height: 0.75rem;
  border-radius: 50%;
  flex-shrink: 0;
}
</style>
