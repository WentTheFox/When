<script setup lang="ts">
/**
 * A searchable dropdown that can show a flag/icon/color dot per row and group headings, used wherever a long list
 * has to be picked from (countries, connections). A native <select> can't show images or filter as you type.
 *
 * `v-model` is the option's `value` (a string), or with `multiple` an array of values: picking then toggles
 * the row and keeps the list open. The list opens on click, filters as you type, and closes on Escape,
 * on picking, or on a click outside. Options sharing a `group` must be adjacent; a heading is drawn
 * wherever the group changes.
 */
import 'flag-icons/css/flag-icons.min.css';
import { computed, nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';
import { CheckIcon, type LucideIcon } from '@lucide/vue';
import AppIcon from './AppIcon.vue';
import SwatchDot from './SwatchDot.vue';

export interface SelectOption {
  value: string;
  text: string;
  /** ISO alpha-2 code: draws that country's flag before the text. */
  flag?: string;
  /** A palette key: draws that color's dot before the text (a source's category color). */
  swatch?: string | null;
  /** A Lucide icon drawn before the text. */
  icon?: LucideIcon;
  group?: string;
  disabled?: boolean;
}

const props = withDefaults(
  defineProps<{ options: SelectOption[]; placeholder?: string; disabled?: boolean; ariaLabel?: string; multiple?: boolean }>(),
  { placeholder: 'Select…' },
);

const model = defineModel<string | string[]>({ default: '' });

const chosen = computed(() => (Array.isArray(model.value) ? model.value : model.value === '' ? [] : [model.value]));
function isChosen(value: string): boolean {
  return Array.isArray(model.value) ? model.value.includes(value) : model.value === value;
}

const id = useId();
const root = ref<HTMLElement | null>(null);
const search = ref<HTMLInputElement | null>(null);
const open = ref(false);
const query = ref('');

const selected = computed(() => (props.multiple ? undefined : props.options.find((o) => o.value === model.value)));
/** The button's label in multiple mode: the chosen names in list order, or the placeholder when none. */
const chosenOptions = computed(() => props.options.filter((o) => chosen.value.includes(o.value)));
const hasSwatches = computed(() => props.options.some((o) => o.swatch));
const hasIcons = computed(() => props.options.some((o) => o.icon));
const hasFlags = computed(() => props.options.some((o) => o.flag));
const visible = computed(() => {
  const q = query.value.trim().toLowerCase();
  const matches = q ? props.options.filter((o) => o.text.toLowerCase().includes(q) || o.value.toLowerCase() === q) : props.options;
  return matches.map((o, i) => ({ ...o, heading: o.group && o.group !== matches[i - 1]?.group ? o.group : '' }));
});

function onDocumentPointerDown(event: PointerEvent): void {
  if (root.value && !root.value.contains(event.target as Node)) open.value = false;
}

// Listen for outside clicks only while the list is open.
watch(open, async (isOpen) => {
  if (isOpen) {
    document.addEventListener('pointerdown', onDocumentPointerDown);
    query.value = '';
    await nextTick();
    search.value?.focus();
  } else {
    document.removeEventListener('pointerdown', onDocumentPointerDown);
  }
});
onBeforeUnmount(() => document.removeEventListener('pointerdown', onDocumentPointerDown));

function pick(option: SelectOption | undefined): void {
  if (!option || option.disabled) return;
  if (props.multiple) {
    model.value = isChosen(option.value) ? chosen.value.filter((v) => v !== option.value) : [...chosen.value, option.value];
    return;
  }
  model.value = option.value;
  open.value = false;
}
</script>

<template>
  <div ref="root" class="position-relative searchable-select" @keydown.esc="open = false">
    <button
      type="button"
      class="form-select form-select-sm text-start text-truncate"
      :class="{ 'text-body-secondary': multiple ? !chosen.length : !selected }"
      :disabled="disabled"
      :aria-label="ariaLabel"
      aria-haspopup="listbox"
      :aria-expanded="open"
      :aria-controls="`${id}-list`"
      @click="open = !open"
    >
      <span v-if="selected?.flag" :class="`fi fi-${selected.flag.toLowerCase()} me-2`" aria-hidden="true" />
      <template v-if="multiple">
        <template v-if="chosenOptions.length">
          <span v-for="(option, index) in chosenOptions" :key="option.value">
            <template v-if="index">, </template><SwatchDot :color-key="option.swatch" class="me-1" />{{ option.text }}
          </span>
        </template>
        <template v-else>{{ placeholder }}</template>
      </template>
      <template v-else-if="selected"><AppIcon v-if="selected.icon" :icon="selected.icon" class="me-2" /><SwatchDot :color-key="selected.swatch" class="me-1" />{{ selected.text }}</template>
      <template v-else>{{ placeholder }}</template>
    </button>
    <div v-if="open" class="searchable-select-panel card shadow-sm position-absolute w-100 mt-1">
      <input
        ref="search"
        v-model="query"
        type="search"
        class="form-control form-control-sm border-0 border-bottom rounded-0 px-3 py-2"
        placeholder="Search…"
        autocomplete="off"
        :aria-controls="`${id}-list`"
        @keydown.enter.prevent="pick(visible.find((o) => !o.disabled))"
      >
      <ul :id="`${id}-list`" class="list-unstyled mb-0 py-1 overflow-auto" role="listbox" :aria-multiselectable="multiple">
        <template v-for="option in visible" :key="option.value">
          <li v-if="option.heading" class="searchable-select-heading px-3 pt-2 pb-1 small fw-semibold text-body-secondary" role="presentation">
            {{ option.heading }}
          </li>
          <li role="option" :aria-selected="isChosen(option.value)">
            <button
              type="button"
              class="dropdown-item d-flex align-items-center py-2 px-3"
              :class="{ active: isChosen(option.value) }"
              :disabled="option.disabled"
              @click="pick(option)"
            >
              <span v-if="option.flag" :class="`fi fi-${option.flag.toLowerCase()} me-2`" aria-hidden="true" />
              <span v-else-if="hasFlags" class="me-2 searchable-select-nobox" aria-hidden="true" />
              <AppIcon v-if="option.icon" :icon="option.icon" class="me-2" />
              <span v-else-if="hasIcons" class="me-2 searchable-select-nobox" aria-hidden="true" />
              <SwatchDot v-if="option.swatch" :color-key="option.swatch" class="me-2" />
              <span v-else-if="hasSwatches" class="me-2 searchable-select-noswatch" aria-hidden="true" />
              <span class="flex-grow-1">{{ option.text }}</span>
              <AppIcon v-if="multiple && isChosen(option.value)" :icon="CheckIcon" class="ms-2" />
            </button>
          </li>
        </template>
        <li v-if="!visible.length" class="px-3 py-2 text-muted small">No match</li>
      </ul>
    </div>
  </div>
</template>

<style scoped>
.searchable-select-panel {
  z-index: 1050;
  min-width: 12rem;
}
.searchable-select-panel ul {
  max-height: 16rem;
}
.searchable-select .dropdown-item {
  white-space: normal;
}
/* Keeps flag-less rows (e.g. "(unset)") aligned with the flagged ones. */
.searchable-select-nobox {
  display: inline-block;
  width: 1.33em;
}
/* The same for rows without a color dot, so names line up. */
.searchable-select-noswatch {
  display: inline-block;
  width: 0.75rem;
  flex-shrink: 0;
}
</style>
