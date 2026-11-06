<script setup lang="ts">
/**
 * A searchable country dropdown with real flag images (flag-icons). A native
 * <select> can't show images, and flag emoji don't render on Windows.
 *
 * `v-model` is the ISO alpha-2 code, '' for "(unset)". The list opens on click,
 * filters as you type, and closes on Escape, on picking, or on a click outside.
 */
import 'flag-icons/css/flag-icons.min.css';
import { computed, nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';
import { countryOptions } from '../dashboard/countries';

const props = defineProps<{ locale?: string; disabled?: boolean }>();

const model = defineModel<string>({ default: '' });

const id = useId();
const root = ref<HTMLElement | null>(null);
const search = ref<HTMLInputElement | null>(null);
const open = ref(false);
const query = ref('');

const options = computed(() => countryOptions(props.locale));
const selected = computed(() => options.value.find((o) => o.value === model.value) ?? options.value[0]);
const visible = computed(() => {
  const q = query.value.trim().toLowerCase();
  return q ? options.value.filter((o) => o.text.toLowerCase().includes(q) || o.value.toLowerCase() === q) : options.value;
});

function onDocumentPointerDown(event: PointerEvent): void {
  if (root.value && !root.value.contains(event.target as Node)) open.value = false;
}

// Listen for outside clicks only while the list is open.
watch(open, async (isOpen) => {
  if (isOpen) {
    document.addEventListener('pointerdown', onDocumentPointerDown);
    query.value = '';
    await nextTick();
    search.value?.focus();
  } else {
    document.removeEventListener('pointerdown', onDocumentPointerDown);
  }
});
onBeforeUnmount(() => document.removeEventListener('pointerdown', onDocumentPointerDown));

function pick(value: string): void {
  model.value = value;
  open.value = false;
}
</script>

<template>
  <div ref="root" class="position-relative country-select" @keydown.esc="open = false">
    <button
      type="button"
      class="form-select form-select-sm text-start"
      :disabled="disabled"
      aria-haspopup="listbox"
      :aria-expanded="open"
      :aria-controls="`${id}-list`"
      @click="open = !open"
    >
      <span v-if="selected.value" :class="`fi fi-${selected.value.toLowerCase()} me-2`" aria-hidden="true" />
      {{ selected.text }}
    </button>
    <div v-if="open" class="country-select-panel card shadow-sm position-absolute w-100 mt-1">
      <input
        ref="search"
        v-model="query"
        type="search"
        class="form-control form-control-sm border-0 border-bottom rounded-0"
        placeholder="Search…"
        autocomplete="off"
        :aria-controls="`${id}-list`"
        @keydown.enter.prevent="visible[0] && pick(visible[0].value)"
      >
      <ul :id="`${id}-list`" class="list-unstyled mb-0 overflow-auto" role="listbox">
        <li v-for="option in visible" :key="option.value" role="option" :aria-selected="option.value === model">
          <button
            type="button"
            class="dropdown-item d-flex align-items-center"
            :class="{ active: option.value === model }"
            @click="pick(option.value)"
          >
            <span :class="option.value ? `fi fi-${option.value.toLowerCase()} me-2` : 'me-2 country-select-nobox'" aria-hidden="true" />
            {{ option.text }}
          </button>
        </li>
        <li v-if="!visible.length" class="px-3 py-2 text-muted small">No match</li>
      </ul>
    </div>
  </div>
</template>

<style scoped>
.country-select-panel {
  z-index: 1050;
}
.country-select-panel ul {
  max-height: 16rem;
}
/* Keeps "(unset)" aligned with the flagged rows. */
.country-select-nobox {
  display: inline-block;
  width: 1.33em;
}
</style>
