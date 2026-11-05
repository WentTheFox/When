<script setup lang="ts">
/**
 * Shows a decrypted value, or — until it has been decrypted — the raw
 * ciphertext blob itself, instead of a blank or "…" placeholder.
 *
 * DISPLAY ONLY. The raw text must never flow into an edit form or be sent
 * anywhere: callers keep their real (decrypted) state separate and only use
 * this to render. `value` is `undefined` while pending; an empty string is a
 * legitimately empty plaintext and renders as such.
 */
defineProps<{ value?: string | null; ciphertext: string }>();
</script>

<template>
  <span v-if="value !== undefined && value !== null">{{ value }}</span>
  <template v-else>
    <span class="visually-hidden">Decrypting…</span>
    <span class="encrypted-raw" aria-hidden="true">{{ ciphertext }}</span>
  </template>
</template>

<style scoped>
.encrypted-raw {
  display: inline-block;
  max-width: 14rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  vertical-align: bottom;
  font-family: var(--bs-font-monospace, monospace);
  font-size: 0.85em;
  opacity: 0.6;
}
</style>
