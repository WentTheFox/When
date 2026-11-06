<script setup lang="ts">
/**
 * The country picker: SearchableSelect fed with every country (flag + name in the viewer's language).
 * `v-model` is the ISO alpha-2 code, '' for "(unset)".
 */
import { computed } from 'vue';
import { countryOptions } from '../dashboard/countries';
import SearchableSelect from './SearchableSelect.vue';

const props = defineProps<{ locale?: string; disabled?: boolean }>();

const model = defineModel<string>({ default: '' });

const options = computed(() => countryOptions(props.locale).map((o) => ({ ...o, flag: o.value || undefined })));
</script>

<template>
  <SearchableSelect v-model="model" :options="options" :disabled="disabled" />
</template>
