<script setup lang="ts">
/**
 * An inline radio group on native inputs, for choice-like custom fields.
 *
 * Not BFormRadio: its `value` prop lists Boolean before String, so Vue casts an
 * empty string to `true`, and an "(unset)" option with value "" silently becomes
 * `true` — never selected for an empty value, and clearing a field stores
 * "true". Native radios have no such casting.
 *
 * `v-model` is the selected choice's value. When the parent has no value yet
 * (undefined) it reads as '' (the default below), so the choice whose value is ''
 * — "(unset)" — is the one selected.
 */
defineProps<{
  choices: readonly { value: string; text: string }[];
  /** Groups the radios (arrow keys move between them) and prefixes their ids; unique per field. */
  name: string;
  disabled?: boolean;
}>();

const model = defineModel<string>({ default: '' });
</script>

<template>
  <div>
    <div v-for="(choice, index) in choices" :key="choice.value" class="form-check form-check-inline">
      <input
        :id="`${name}-${index}`"
        v-model="model"
        class="form-check-input"
        type="radio"
        :name="name"
        :value="choice.value"
        :disabled="disabled"
      >
      <label class="form-check-label" :for="`${name}-${index}`">{{ choice.text }}</label>
    </div>
  </div>
</template>
