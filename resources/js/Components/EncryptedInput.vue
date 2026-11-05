<script setup lang="ts">
/**
 * Drop-in for BFormInput / BFormTextarea that holds an E2EE value.
 *
 * `v-model` always binds to the REAL (decrypted) value, exactly as with the
 * plain Bootstrap inputs. While `pending` (decryption hasn't finished) the
 * field shows the raw ciphertext instead — dimmed, monospace, and disabled —
 * and never writes to the model, so the raw blob can't end up in the form
 * state and be re-encrypted and saved as if it were plaintext. Once `pending`
 * clears, it is an ordinary editable input. Every other attribute (size,
 * type, rows, placeholder, autocomplete, class…) passes through to the
 * underlying input.
 *
 * Pair with EncryptedText.vue for read-only display of the same values.
 */
import { BFormInput, BFormTextarea } from 'bootstrap-vue-next';
import { computed } from 'vue';

defineOptions({ inheritAttrs: false });

const props = defineProps<{
  /** The raw ciphertext to display while pending; null/undefined when the field has none. */
  ciphertext?: string | null;
  /** True until the value has been decrypted. */
  pending: boolean;
  multiline?: boolean;
  disabled?: boolean;
}>();

const model = defineModel<string>({ default: '' });

const shown = computed(() => (props.pending ? (props.ciphertext ?? '') : model.value));

function onUpdate(value: string | number | null | undefined): void {
  if (props.pending) return;
  model.value = value == null ? '' : String(value);
}
</script>

<template>
  <BFormTextarea
    v-if="multiline"
    v-bind="$attrs"
    :model-value="shown"
    :disabled="pending || disabled"
    :aria-busy="pending || undefined"
    :class="{ 'encrypted-pending': pending }"
    @update:model-value="onUpdate"
  />
  <BFormInput
    v-else
    v-bind="$attrs"
    :model-value="shown"
    :disabled="pending || disabled"
    :aria-busy="pending || undefined"
    :class="{ 'encrypted-pending': pending }"
    @update:model-value="onUpdate"
  />
</template>

<style scoped>
.encrypted-pending {
  font-family: var(--bs-font-monospace, monospace);
  font-size: 0.85em;
  opacity: 0.6;
  text-overflow: ellipsis;
}
</style>
