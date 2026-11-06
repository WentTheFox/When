<script setup lang="ts">
/**
 * Renders one Lucide icon: the palette's owner-selectable ones (resources/js/free/icon-palette.ts) and every
 * interface icon alike. Sized in em (see .wtf-icon in dark-theme.css) so it follows the surrounding
 * font-size, and drawn in currentColor. Classes and attributes fall through onto the <svg>.
 *
 *   spin      turns it endlessly (loading and refreshing); honours prefers-reduced-motion.
 *   size="2x" twice the surrounding font-size, for the page-level loading overlays.
 */
import type { LucideIcon } from '@lucide/vue';

defineProps<{
  icon: LucideIcon;
  spin?: boolean;
  size?: '2x';
}>();
</script>

<template>
  <component
    :is="icon"
    class="wtf-icon"
    :class="{ 'wtf-icon-spin': spin, 'wtf-icon-2x': size === '2x' }"
    aria-hidden="true"
  />
</template>
