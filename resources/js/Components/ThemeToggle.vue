<script setup lang="ts">
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome';
import { useTheme } from '../composables/useTheme';

const { icon, cycle } = useTheme();
</script>

<template>
  <button type="button" class="nav-link btn btn-link" aria-label="Change theme" @click="cycle">
    <FontAwesomeIcon :icon="icon" />
  </button>
</template>
