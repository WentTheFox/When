<script setup lang="ts">
/**
 * Settings.vue's "Activity localizations" card — its own top-level card below
 * "Event title matching rules", not part of the shared `form`/submit():
 * ActivityLocalizations.vue already saves each role independently through its own
 * /settings/activity-localization/* endpoints, so this card needs no form wrapper
 * or footer save button of its own.
 */
import { BCard } from 'bootstrap-vue-next';
import ActivityLocalizations from './ActivityLocalizations.vue';

defineProps<{
  activityLocalizations: { id: string; pattern: string; label: Record<string, string>; sort_order: number }[];
}>();
</script>

<template>
  <BCard class="mb-4">
    <ActivityLocalizations :initial="activityLocalizations" />
  </BCard>
</template>
