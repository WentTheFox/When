<script setup lang="ts">
import { BButton, BCard, BFormGroup, BFormInput, BTooltip } from 'bootstrap-vue-next';
import { computed, ref, watch } from 'vue';
import { useResolvedTheme } from '../composables/useTheme';
import { getColorPalette, swatchByKey } from '../free/color-palette';

interface CategoryOption {
  id: string;
  color_key: string | null;
  label: string;
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
const selectedId = ref<string | null>(null);
const editName = ref('');
const editColorKey = ref<string | null>(null);

const sortedCategories = computed(() => [...props.categories].sort((a, b) => a.label.localeCompare(b.label)));
const filteredCategories = computed(() => {
  const query = newName.value.trim().toLowerCase();
  if (!query) return sortedCategories.value;
  return sortedCategories.value.filter((c) => c.label.toLowerCase().includes(query));
});

function add(): void {
  if (!newName.value) return;
  emit('add', newName.value);
  newName.value = '';
}

function select(id: string): void {
  selectedId.value = id;
  const category = props.categories.find((c) => c.id === id);
  editName.value = category?.label ?? '';
  editColorKey.value = category?.color_key ?? null;
}

// Same reasoning as SourcesPanel.vue's equivalent watcher — keeps the edit
// fields in sync if this category's data changes from elsewhere without
// clobbering an in-progress edit on a *different* selected category.
watch(() => props.categories, (categories) => {
  if (!selectedId.value) return;
  const current = categories.find((c) => c.id === selectedId.value);
  if (current) {
    editName.value = current.label;
    editColorKey.value = current.color_key;
  }
}, { deep: true });

function save(): void {
  if (!selectedId.value || !editName.value) return;
  emit('update', selectedId.value, editName.value, editColorKey.value);
}

function remove(): void {
  if (!selectedId.value) return;
  if (!window.confirm('Delete this category? Sources using it keep their color unassigned.')) return;
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
  <BCard class="mb-4">
    <h2 class="h5 mb-3">Source categories</h2>
    <p class="small text-muted">
      Give a category a color to distinguish its sources on the dashboard's connections graph.
    </p>
    <div class="row">
      <div class="col-md-4">
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
            {{ category.label }}
          </button>
        </div>
      </div>

      <div class="col-md-8">
        <template v-if="selectedId">
          <BFormGroup label="Name" class="mb-3">
            <BFormInput v-model="editName" type="text" size="sm" />
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
                :style="{ '--wtf-swatch-light': swatch.light, '--wtf-swatch-dark': swatch.dark }"
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
          <BButton size="sm" variant="primary" @click="save">Save</BButton>
          <BButton size="sm" variant="outline-danger" @click="remove">Delete</BButton>
        </template>
        <p v-else class="text-muted">Select a category on the left to rename, recolor, or delete it.</p>
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
  </BCard>
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
