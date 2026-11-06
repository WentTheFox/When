<script setup lang="ts">
/**
 * Renders one icon from the curated palette (resources/js/free/icon-palette.ts). Sized in em (see .wtf-palette-icon in dark-theme.css) so it
 * follows the surrounding font-size the way the old Font Awesome glyphs did; colour is currentColor.
 * Classes and attributes fall through onto the <svg>.
 */
import type { PaletteIcon } from '../free/icon-palette';

defineProps<{ icon: PaletteIcon }>();
</script>

<template>
  <component :is="icon" class="wtf-palette-icon" aria-hidden="true" />
</template>
