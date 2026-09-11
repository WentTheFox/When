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
 *
 * Not every color this picker is used for is a translucent calendar-block
 * wash, though — accent/secondary/the current-time marker are all solid
 * fills (a button background, a plain line), never blended over the page
 * background the way a block is. `preview="solid"` renders those as a
 * plain opaque swatch with plain black/white contrast text instead of
 * pretending they're a block; `previewSlot` (only meaningful for
 * preview="wash", the default) says which BLOCK_ALPHA/label-tint slot to
 * preview as, so this same component can be reused for every *_color_key
 * field (SettingsPublicPageCard.vue) instead of just the highlighted-only
 * case ActivityLocalizationForm.vue needs.
 */
import { BDropdown } from 'bootstrap-vue-next';
import { computed } from 'vue';
import { getColorPalette } from '../free/color-palette';
import type { ColorSlot, ColorSwatch } from '../free/color-palette';
import { BLOCK_ALPHA, hexToRgba, yiqTextColor } from '../free/color-utils';

const props = withDefaults(defineProps<{
  /** Accessible name for the toggle button. */
  label: string;
  /** Defaults to the main color palette (getColorPalette()) — pass e.g. getNowColorPresets() to pick from a different catalog instead. */
  options?: ColorSwatch[];
  preview?: 'wash' | 'solid';
  /** Which calendar block type to preview the wash/label-tint as — required (and only meaningful) when preview="wash". */
  previewSlot?: ColorSlot;
}>(), {
  options: undefined,
  preview: 'wash',
  previewSlot: 'highlighted',
});

const modelValue = defineModel<string | null>({ default: null });

const allColors = computed(() => props.options ?? getColorPalette());

const currentSwatch = computed(() => allColors.value.find((c) => c.key === modelValue.value));

/** Clicking the already-selected color again clears it back to unset — same convention as IconPicker.vue's select(). */
function select(key: string): void {
  modelValue.value = modelValue.value === key ? null : key;
}

/**
 * "Unavailable" doesn't tint its label toward the swatch's own hue like
 * every other block type does (see dark-theme.css's own comment on
 * --app-fcal-text-unavailable) — it's a fixed near-black/near-white
 * literal regardless of the busy color chosen, so this reads that var
 * directly (already scoped to the right fixed theme by .wtf-theme-preview)
 * rather than computing a color-mix() that the real block would never
 * actually use.
 *
 * The wash itself is painted as its own gradient layer over var(--app-bg)
 * (rather than just setting backgroundColor to the translucent rgba
 * directly) because this chip isn't necessarily sitting directly on that
 * background in the real DOM (it's inside a dropdown menu) — layering it
 * explicitly makes the rendered result match the calendar regardless of
 * what's actually behind the chip.
 */
function chipStyle(hex: string, theme: 'light' | 'dark'): Record<string, string> {
  if (props.preview === 'solid') {
    return {
      backgroundColor: hex,
      color: yiqTextColor(hex),
    };
  }

  const wash = hexToRgba(hex, (BLOCK_ALPHA[theme] as Record<ColorSlot, number>)[props.previewSlot]);
  const textColor = props.previewSlot === 'busy'
    ? 'var(--app-fcal-text-unavailable)'
    : `color-mix(in srgb, ${hex} 65%, var(--app-text) 35%)`;

  return {
    background: `linear-gradient(${wash}, ${wash}), var(--app-bg)`,
    color: textColor,
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
      <!-- Same effect as clicking the already-selected swatch again inside
           the menu (select()'s own toggle-to-clear) — this is just a more
           discoverable way to reach it without having to open the dropdown
           and re-find that exact swatch. @click.stop keeps it from also
           toggling the dropdown itself open/closed. -->
      <button
        v-if="currentSwatch"
        type="button"
        class="btn-close wtf-color-picker-clear"
        aria-label="Clear color"
        @click.stop="modelValue = null"
      />
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
