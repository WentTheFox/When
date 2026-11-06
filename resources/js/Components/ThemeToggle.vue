<script setup lang="ts">
import AppIcon from './AppIcon.vue';
import { useTheme } from '../composables/useTheme';

const { icon, cycle } = useTheme();
</script>

<template>
  <button type="button" class="nav-link btn btn-link" aria-label="Change theme" @click="cycle">
    <AppIcon :icon="icon" />
  </button>
</template>
