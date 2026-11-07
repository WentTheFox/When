<script setup lang="ts">
/**
 * One rule icon (iconRules.ts): a palette icon, or a country flag when the key is `flag:xx`.
 * Renders nothing for a key it does not know.
 */
import 'flag-icons/css/flag-icons.min.css';
import { computed } from 'vue';
import { flagIconCode } from '../dashboard/countries';
import { paletteIconFor } from '../free/icon-palette';
import AppIcon from './AppIcon.vue';

const props = defineProps<{ iconKey: string }>();

const flag = computed(() => flagIconCode(props.iconKey));
const palette = computed(() => paletteIconFor(props.iconKey));
</script>

<template>
  <span v-if="flag" :class="`fi fi-${flag.toLowerCase()} wtf-rule-flag`" aria-hidden="true" />
  <AppIcon v-else-if="palette" :icon="palette" />
</template>
