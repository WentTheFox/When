<script setup lang="ts">
/**
 * The "Live preview — <pattern>" caption plus its PatternPreview, as one
 * reusable unit — used both by SettingsEventMatchingCard.vue's 11 fields
 * (each in its own boxed .wtf-pattern-preview-panel column) and
 * RegexVisualEditorModal.vue's own preview, so the two can never drift
 * apart in wording or behavior. Everything shown beyond the pattern
 * itself — the "splitting on" lead-in for highlight_split_pattern, the
 * sample-words caption for a 'tokens'-mode field — is derived from
 * `config` (see patternPreviewTypes.ts) rather than passed in separately,
 * so a caller only ever hands over the field's own live pattern and its
 * PatternPreviewConfig.
 */
import { computed } from 'vue';
import PatternPreview from './PatternPreview.vue';
import RegexHighlightedCode from './RegexHighlightedCode.vue';
import { resolvePatternPreviewProps, type PatternPreviewConfig } from './patternPreviewTypes';

const props = withDefaults(defineProps<{
  /**
   * The field's own current pattern, exactly as typed — left blank as-is
   * (not resolved to a fallback) unless blank genuinely means "use this
   * other pattern instead" (highlight_clause_pattern/highlight_split_pattern,
   * which fall back to a real default *regex* when blank — the caller
   * resolves that case before passing `pattern` here, since it's the
   * actual value under test either way). For every other field, blank
   * genuinely means "off" — don't resolve it to `blankLabel` before
   * passing it in, or that label text would get compiled as the pattern.
   */
  pattern: string | null;
  config: PatternPreviewConfig;
  /** Shown in place of the pattern when it's blank and blank isn't a real fallback regex — e.g. "(blank, off)". */
  blankLabel?: string;
  showReset?: boolean;
}>(), {
  blankLabel: '(empty)',
  showReset: true,
});

const previewModelValue = defineModel<string | null>('previewModelValue', { default: null });

const previewProps = computed(() => resolvePatternPreviewProps(props.pattern ?? '', props.config));

const sampleWordsCaption = computed(() => {
  if (props.config.mode !== 'tokens' || !props.config.sampleWords?.length) return null;
  return `against sample configured words ${props.config.sampleWords.map((word) => `"${word}"`).join(', ')}`;
});
</script>

<template>
  <div class="wtf-pattern-preview-panel">
    <p class="small text-muted mb-1">
      Live preview —
      <template v-if="config.livePatternTarget === 'splitPattern'">splitting on</template>
      <RegexHighlightedCode v-if="pattern" :pattern="pattern" />
      <code v-else>{{ blankLabel }}</code>
      <template v-if="sampleWordsCaption">
        <br><span class="text-muted">({{ sampleWordsCaption }})</span>
      </template>
    </p>
    <PatternPreview
      v-model="previewModelValue"
      v-bind="previewProps"
      :show-reset="showReset"
    />
  </div>
</template>
