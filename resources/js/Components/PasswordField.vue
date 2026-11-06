<script setup lang="ts">
import { EyeIcon, EyeOffIcon } from '@lucide/vue';
import AppIcon from './AppIcon.vue';
import { BButton } from 'bootstrap-vue-next';
import { ref } from 'vue';

defineProps<{
  id: string;
  modelValue: string;
  required?: boolean;
  autofocus?: boolean;
}>();

defineEmits<{ 'update:modelValue': [value: string] }>();

const show = ref(false);
</script>

<template>
  <div class="input-group">
    <input
      :id="id"
      :value="modelValue"
      :type="show ? 'text' : 'password'"
      class="form-control"
      :required="required"
      :autofocus="autofocus"
      @input="$emit('update:modelValue', ($event.target as HTMLInputElement).value)"
    >
    <BButton
      variant="outline-secondary"
      :aria-label="show ? 'Hide password' : 'Show password'"
      @click="show = !show"
    >
      <AppIcon :icon="show ? EyeOffIcon : EyeIcon" />
    </BButton>
  </div>
</template>
