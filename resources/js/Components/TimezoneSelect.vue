<script setup lang="ts">
/**
 * The time zone picker: a multi-select (SearchableSelect in `multiple` mode) fed with every IANA zone, grouped by
 * region. `v-model` is the array of chosen zone ids.
 */
import { computed } from 'vue';
import { timezoneOptions } from '../dashboard/timezones';
import SearchableSelect from './SearchableSelect.vue';

defineProps<{ disabled?: boolean; ariaLabel?: string }>();

const model = defineModel<string[]>({ default: () => [] });

// Chosen ids the list doesn't know (aliases) stay selectable (and so removable).
const options = computed(() => timezoneOptions(model.value));
</script>

<template>
  <SearchableSelect v-model="model" multiple :options="options" placeholder="Add a time zone…" :aria-label="ariaLabel ?? 'Time zones'" :disabled="disabled" />
</template>
