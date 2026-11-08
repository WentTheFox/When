<script setup lang="ts">
import { BTooltip } from 'bootstrap-vue-next';
import { computed } from 'vue';
import AppIcon from './AppIcon.vue';
import { useTheme } from '../composables/useTheme';

const { icon, preference, cycle } = useTheme();

const label = computed(() => `Change theme (currently: ${preference.value})`);
</script>

<template>
  <button id="theme-toggle" type="button" class="nav-link btn btn-link" :aria-label="label" @click="cycle">
    <AppIcon :icon="icon" />
  </button>
  <BTooltip target="theme-toggle" placement="bottom">{{ label }}</BTooltip>
</template>
