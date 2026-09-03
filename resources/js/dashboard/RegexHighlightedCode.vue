<script setup lang="ts">
import { highlightPatternHtml } from './regexHighlight';

/**
 * A read-only `<code>` span for a Suggested/Default pattern value, colored
 * with the same wtf-regex-tok-* classes RegexPatternInput.vue's live
 * editor uses — so a field's suggested starting value reads exactly like
 * it would once typed into the field itself, instead of looking like
 * plain, unstyled text next to a colorful input.
 */
defineProps<{ pattern: string }>();
</script>

<template>
  <code class="wtf-regex-highlighted-code" v-html="highlightPatternHtml(pattern)" />
</template>
