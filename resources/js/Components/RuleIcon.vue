<script setup lang="ts">
/**
 * One rule icon (iconRules.ts): a palette icon, a country flag when the key is `flag:xx`, or a brand mark for `brand:xx`.
 * Renders nothing for a key it does not know.
 */
import 'flag-icons/css/flag-icons.min.css';
import { computed } from 'vue';
import { brandIconPath } from '../dashboard/brandIcons';
import { flagIconCode } from '../dashboard/countries';
import { paletteIconFor } from '../free/icon-palette';
import AppIcon from './AppIcon.vue';

const props = defineProps<{ iconKey: string }>();

const flag = computed(() => flagIconCode(props.iconKey));
const brand = computed(() => brandIconPath(props.iconKey));
const palette = computed(() => paletteIconFor(props.iconKey));
</script>

<template>
  <span v-if="flag" :class="`fi fi-${flag.toLowerCase()} wtf-rule-flag`" aria-hidden="true" />
  <svg v-else-if="brand" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="wtf-icon" aria-hidden="true"><path :d="brand" /></svg>
  <AppIcon v-else-if="palette" :icon="palette" />
</template>
