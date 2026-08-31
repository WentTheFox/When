<script setup lang="ts">
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome';
import { faCheck, faXmark } from '@fortawesome/free-solid-svg-icons';
import { computed } from 'vue';

/**
 * Client-side stand-in for App\Support\Regex::tryMatch — same delimiter-
 * free pattern, case-insensitive + unicode flags, fails closed (no match)
 * on an invalid pattern instead of throwing. This is JavaScript's RegExp
 * engine, not PHP's PCRE, so treat it as a close approximation of the real
 * server-side match rather than a guarantee — differences would only show
 * up on genuinely exotic regex features, not on anything shown here.
 */
function tryMatch(pattern: string, subject: string): RegExpExecArray | null {
  if (!pattern) {
    return null;
  }

  try {
    return new RegExp(pattern, 'iu').exec(subject);
  } catch {
    return null;
  }
}

/**
 * Mirrors HighlightMatcher::matchTokens: comma-split, case-sensitive
 * substring check — returns every configured word that matches (a clause
 * can name more than one person, e.g. "with Charlie, Alice"), not just the
 * first, same as the real backend.
 */
function matchTokens(tokenStr: string, words: string[]): string[] {
  const tokens = tokenStr.split(',').map((t) => t.trim()).filter((t) => t !== '');
  const matched: string[] = [];

  for (const word of words) {
    if (tokens.some((token) => token.includes(word))) matched.push(word);
  }

  return matched;
}

const props = defineProps<{
  pattern: string;
  examples: string[];
  /**
   * 'match': DND/nap — did it match at all?
   * 'extract': activity clause — what did group 1 capture, verbatim?
   * 'tokens': highlight clause — split group 1 on commas, does any token contain a configured word (sampleWords)?
   */
  mode: 'match' | 'extract' | 'tokens';
  /** Only used in 'tokens' mode — stand-in for a share link's own configured highlight words. */
  sampleWords?: string[];
}>();

const results = computed(() => props.examples.map((title) => {
  const match = tryMatch(props.pattern, title);

  if (props.mode === 'tokens') {
    const words = match?.[1] ? matchTokens(match[1], props.sampleWords ?? []) : [];
    return { title, matched: words.length > 0, captured: words.join(', ') };
  }

  return {
    title,
    matched: match !== null,
    captured: props.mode === 'extract' ? match?.[1]?.trim() : undefined,
  };
}));
</script>

<template>
  <ul class="list-unstyled mb-0 small">
    <li v-for="r in results" :key="r.title" class="d-flex align-items-start mb-1">
      <span class="me-2" :class="r.matched ? 'text-success' : 'text-muted'" style="width: 1em;">
        <FontAwesomeIcon :icon="r.matched ? faCheck : faXmark" />
      </span>
      <span>
        <code>{{ r.title }}</code>
        <template v-if="mode === 'extract'">
          <span v-if="r.matched" class="text-muted"> → activity: "<strong>{{ r.captured || '(empty)' }}</strong>"</span>
          <span v-else class="text-muted"> → no activity</span>
        </template>
        <template v-else-if="mode === 'tokens'">
          <span v-if="r.matched" class="text-muted"> → highlights "<strong>{{ r.captured }}</strong>"</span>
          <span v-else class="text-muted"> → no highlight (against sample words {{ (sampleWords ?? []).join(', ') }})</span>
        </template>
      </span>
    </li>
  </ul>
</template>
