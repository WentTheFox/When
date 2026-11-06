<script setup lang="ts">
/**
 * A small round dot in a palette color, shown before a name to say which category (group) it belongs to.
 * `colorKey` is a palette key (see free/color-palette.ts); nothing is drawn for an unset or unknown one, so
 * callers can put it in front of every name without checking. The hex follows the live light/dark theme.
 */
import { computed } from 'vue';
import { useResolvedTheme } from '../composables/useTheme';
import { swatchByKey } from '../free/color-palette';

const props = defineProps<{ colorKey?: string | null }>();

const theme = useResolvedTheme();
const hex = computed(() => {
  const swatch = swatchByKey(props.colorKey ?? undefined);

  return swatch ? swatch[theme.value] : undefined;
});
</script>

<template>
  <span v-if="hex" class="wtf-swatch-dot" :style="{ background: hex }" aria-hidden="true"></span>
</template>

<style scoped>
.wtf-swatch-dot {
  display: inline-block;
  width: 0.75rem;
  height: 0.75rem;
  border-radius: 50%;
  flex-shrink: 0;
  vertical-align: baseline;
}
</style>
