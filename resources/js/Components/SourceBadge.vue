<script setup lang="ts">
/**
 * A source's icon logo: a round badge holding the icon in the source's (category) color, on a background that is
 * black (dark theme) or white (light theme) tinted with that same color. Only drawn for a source that has an
 * icon, so callers can put it next to every source and fall back to the plain color dot otherwise.
 */
import { computed } from 'vue';
import { useResolvedTheme } from '../composables/useTheme';
import { swatchByKey } from '../free/color-palette';
import RuleIcon from './RuleIcon.vue';

const props = withDefaults(defineProps<{ iconKey: string; colorKey?: string | null; size?: string }>(), { size: '1.75rem' });

const theme = useResolvedTheme();
/** Same fallback hue the graph gives a source without a category color. */
const hex = computed(() => {
  const swatch = swatchByKey(props.colorKey ?? undefined) ?? swatchByKey('blue');

  return swatch ? swatch[theme.value] : '#6181b6';
});
const style = computed(() => ({
  width: props.size,
  height: props.size,
  color: hex.value,
  background: `color-mix(in srgb, ${hex.value} 18%, ${theme.value === 'dark' ? '#000' : '#fff'})`,
}));
</script>

<template>
  <span class="wtf-source-badge" :style="style" aria-hidden="true"><RuleIcon :icon-key="iconKey" /></span>
</template>

<style scoped>
.wtf-source-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  flex-shrink: 0;
  font-size: 0.85em;
  vertical-align: middle;
  overflow: hidden;
}
</style>
