<script setup lang="ts">
/**
 * The language picker: a multi-select (SearchableSelect in `multiple` mode) fed with every language by its name in
 * the viewer's language. `v-model` is the array of chosen language codes.
 */
import { computed } from 'vue';
import { languageOptions } from '../dashboard/languages';
import SearchableSelect from './SearchableSelect.vue';

const props = defineProps<{ locale?: string; disabled?: boolean; ariaLabel?: string }>();

const model = defineModel<string[]>({ default: () => [] });

// Chosen codes the list doesn't know stay selectable (and so removable).
const options = computed(() => languageOptions(props.locale, model.value));
</script>

<template>
  <SearchableSelect v-model="model" multiple :options="options" placeholder="Add a language…" :aria-label="ariaLabel ?? 'Languages'" :disabled="disabled" />
</template>
