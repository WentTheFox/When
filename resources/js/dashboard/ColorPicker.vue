<script setup lang="ts">
/**
 * Single-swatch picker for one form field — the ColorPicker sibling of
 * IconPicker.vue, used the same way (ActivityLocalizationForm.vue's
 * per-role color override). Deliberately simpler than IconPicker.vue: the
 * color catalog (getColorPalette()) is small enough (~24 swatches) that it
 * doesn't need IconPicker's search/grouping — but it does need to satisfy a
 * requirement IconPicker never had: showing each swatch's name legibly
 * against BOTH its light and dark hex, the way it'll actually render in
 * either theme, rather than making that discoverable only via hover
 * tooltip (the old always-expanded wtf-swatch-grid's approach, which just
 * shows a color circle and relies on a shared BTooltip for the label).
 */
import { BDropdown } from 'bootstrap-vue-next';
import { computed } from 'vue';
import { getColorPalette } from '../free/color-palette';
import { yiqTextColor } from '../free/color-utils';

const props = defineProps<{
  /** Accessible name for the toggle button. */
  label: string;
}>();

const modelValue = defineModel<string | null>({ default: null });

const allColors = getColorPalette();

const currentSwatch = computed(() => allColors.find((c) => c.key === modelValue.value));

/** Clicking the already-selected color again clears it back to unset — same convention as IconPicker.vue's select(). */
function select(key: string): void {
  modelValue.value = modelValue.value === key ? null : key;
}
</script>

<template>
  <BDropdown
    variant="outline-secondary"
    toggle-class="wtf-color-picker-toggle"
    menu-class="wtf-color-picker-menu"
    :aria-label="props.label"
  >
    <template #button-content>
      <span
        v-if="currentSwatch"
        class="wtf-swatch-btn wtf-color-picker-toggle-swatch"
        :style="{ '--app-swatch-light': currentSwatch.light, '--app-swatch-dark': currentSwatch.dark }"
      />
      <span class="small">{{ currentSwatch?.label ?? 'Choose color' }}</span>
    </template>
    <div class="wtf-color-picker-scroll" @click.stop>
      <button
        v-for="swatch in allColors"
        :key="swatch.key"
        type="button"
        class="wtf-color-picker-row"
        :class="{ 'wtf-color-picker-row-active': modelValue === swatch.key }"
        :aria-pressed="modelValue === swatch.key"
        @click="select(swatch.key)"
      >
        <span class="wtf-color-picker-chip" :style="{ backgroundColor: swatch.light, color: yiqTextColor(swatch.light) }">{{ swatch.label }}</span>
        <span class="wtf-color-picker-chip" :style="{ backgroundColor: swatch.dark, color: yiqTextColor(swatch.dark) }">{{ swatch.label }}</span>
      </button>
    </div>
  </BDropdown>
</template>
