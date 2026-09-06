<script setup lang="ts">
/**
 * Single-swatch picker for one form field — the ColorPicker sibling of
 * IconPicker.vue, used the same way (ActivityLocalizationForm.vue's
 * per-role color override). Deliberately simpler than IconPicker.vue: the
 * color catalog (getColorPalette()) is small enough (~24 swatches) that it
 * doesn't need IconPicker's search/grouping — but it does need to satisfy a
 * requirement IconPicker never had: showing each swatch's name legibly
 * against BOTH its light and dark hex, rendered THE SAME WAY it'll
 * actually look on a highlighted calendar block — the translucent
 * BLOCK_ALPHA wash over that theme's own page background, with the label
 * tinted via the same color-mix() formula dark-theme.css's own
 * --app-fcal-text-highlighted uses — not just a flat swatch hex with a
 * generic black/white YIQ-contrast label.
 *
 * Each theme chip carries .wtf-theme-preview + :data-bs-theme so var(
 * --app-bg)/var(--app-text) below resolve to that FIXED theme's own value
 * regardless of the page's live theme — the same scoping
 * SettingsPublicPageCard.vue's dual light/dark preview panels already rely
 * on (see dark-theme.css's own doc comment on that class for why a plain
 * nested [data-bs-theme] wouldn't work: --app-bg/--app-text are declared
 * :root-scoped, Bootstrap's own convention).
 */
import { BDropdown } from 'bootstrap-vue-next';
import { computed } from 'vue';
import { getColorPalette } from '../free/color-palette';
import { BLOCK_ALPHA, hexToRgba } from '../free/color-utils';

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

/**
 * The wash is painted as its own gradient layer over var(--app-bg) (rather
 * than just setting backgroundColor to the translucent rgba directly)
 * because this chip isn't necessarily sitting directly on that background
 * in the real DOM (it's inside a dropdown menu) — layering it explicitly
 * makes the rendered result match the calendar regardless of what's
 * actually behind the chip.
 */
function chipStyle(hex: string, theme: 'light' | 'dark'): Record<string, string> {
  const wash = hexToRgba(hex, BLOCK_ALPHA[theme].highlighted);
  return {
    background: `linear-gradient(${wash}, ${wash}), var(--app-bg)`,
    color: `color-mix(in srgb, ${hex} 65%, var(--app-text) 35%)`,
  };
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
        <span
          class="wtf-theme-preview wtf-color-picker-chip"
          data-bs-theme="light"
          :style="chipStyle(swatch.light, 'light')"
        >{{ swatch.label }}</span>
        <span
          class="wtf-theme-preview wtf-color-picker-chip"
          data-bs-theme="dark"
          :style="chipStyle(swatch.dark, 'dark')"
        >{{ swatch.label }}</span>
      </button>
    </div>
  </BDropdown>
</template>
