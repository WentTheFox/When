<script setup lang="ts">
/**
 * The one page heading of every signed-in page: an <h1>, an optional muted
 * one-line description under it, and optional actions on the right. It sits
 * directly on the page background, above any flash message and above the
 * page's cards (never inside one), so every page starts the same way.
 *
 * Inside a card, section titles are `<h2 class="h5 mb-3">`.
 */
defineProps<{
  title: string;
  subtitle?: string;
}>();
</script>

<template>
  <header class="wtf-page-header d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
      <h1 class="h3 mb-0">{{ title }}</h1>
      <p v-if="subtitle" class="text-muted small mb-0 mt-1">{{ subtitle }}</p>
    </div>
    <div v-if="$slots.actions" class="d-flex gap-2">
      <slot name="actions" />
    </div>
  </header>
</template>
