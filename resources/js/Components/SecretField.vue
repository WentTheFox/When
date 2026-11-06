<script setup lang="ts">
/**
 * A saved-secret text field: a labelled input that is masked until revealed
 * with the eye button, a "Configured" / "Not set" badge in its label, a
 * description and an error line. Used for the calendar URL and the VRCX Headless API key,
 * which are the same kind of value: §0.2 tier, shown back to the owner (the
 * server can decrypt them either way, so hiding them from their own owner would
 * only add confusion), but not left readable on screen by default.
 */
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome';
import { faEye, faEyeSlash } from '@fortawesome/free-solid-svg-icons';
import { BBadge, BButton, BFormGroup, BFormInput, BInputGroup } from 'bootstrap-vue-next';
import { ref } from 'vue';

defineProps<{
  id: string;
  label: string;
  /** What the value is, for the reveal button's accessible name ("Show {noun}"). */
  noun: string;
  /** Whether a value is already saved (drives the badge). */
  configured: boolean;
  description?: string;
  error?: string;
  placeholder?: string;
  autocomplete?: string;
}>();

const model = defineModel<string>({ default: '' });
const emit = defineEmits<{ input: [event: Event] }>();

const revealed = ref(false);
</script>

<template>
  <BFormGroup :label-for="id" class="mb-3">
    <template #label>
      {{ label }}
      <BBadge v-if="configured" variant="success" class="ms-1">Configured</BBadge>
      <BBadge v-else variant="secondary" class="ms-1">Not set</BBadge>
    </template>
    <BInputGroup>
      <BFormInput
        :id="id"
        v-model="model"
        :type="revealed ? 'text' : 'password'"
        :placeholder="placeholder"
        :autocomplete="autocomplete ?? 'off'"
        @input="emit('input', $event)"
      />
      <BButton
        variant="outline-secondary"
        :aria-label="revealed ? `Hide ${noun}` : `Show ${noun}`"
        @click="revealed = !revealed"
      >
        <FontAwesomeIcon :icon="revealed ? faEyeSlash : faEye" />
      </BButton>
    </BInputGroup>
    <template v-if="description" #description>{{ description }}</template>
    <div v-if="error" class="text-danger small">{{ error }}</div>
  </BFormGroup>
</template>
