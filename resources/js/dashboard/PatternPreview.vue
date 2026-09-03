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
 * Same split-and-trim behavior as HighlightMatcher::matchTokens (falling
 * back to "[,&/]" when unset, same as HighlightMatcher::
 * DEFAULT_SPLIT_PATTERN) — just returns every resulting token rather than
 * only the ones that happen to match a configured word, so both 'tokens'
 * mode (matchTokens below) and 'split' mode (which shows every piece,
 * matched or not) share one splitting implementation. An invalid split
 * pattern fails closed to "the whole clause is one token" rather than
 * losing the match entirely, same fail-closed contract as
 * App\Support\Regex::trySplit.
 */
function splitIntoTokens(tokenStr: string, splitPattern: string): string[] {
  let rawTokens: string[];
  try {
    rawTokens = tokenStr.split(new RegExp(splitPattern || '[,&/]', 'iu'));
  } catch {
    rawTokens = [tokenStr];
  }

  return rawTokens.map((t) => t.trim()).filter((t) => t !== '');
}

/**
 * Mirrors HighlightMatcher::matchTokens: case-sensitive substring check —
 * returns every configured word that matches (a clause can name more than
 * one person, e.g. "with Charlie, Alice"), not just the first, same as the
 * real backend.
 */
function matchTokens(tokenStr: string, words: string[], splitPattern: string): string[] {
  const tokens = splitIntoTokens(tokenStr, splitPattern);
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
   * 'tokens': highlight clause — split group 1 on the split pattern, does any token contain a configured word (sampleWords)?
   * 'split': highlight name-split expression — show every resulting piece (not just the ones that match a sample word), so an owner can see exactly how their clause gets divided.
   */
  mode: 'match' | 'extract' | 'tokens' | 'split';
  /** Used in 'tokens'/'split' mode — stand-in for a share link's own configured highlight words. */
  sampleWords?: string[];
  /** Used in 'tokens'/'split' mode — the owner's highlight_split_pattern (or its default). */
  splitPattern?: string;
}>();

const results = computed(() => props.examples.map((title) => {
  const match = tryMatch(props.pattern, title);

  if (props.mode === 'tokens') {
    const words = match?.[1] ? matchTokens(match[1], props.sampleWords ?? [], props.splitPattern ?? '') : [];
    return { title, matched: words.length > 0, captured: words.join(', '), splitTokens: undefined };
  }

  if (props.mode === 'split') {
    const sample = props.sampleWords ?? [];
    const allTokens = match?.[1] ? splitIntoTokens(match[1], props.splitPattern ?? '') : [];
    const splitTokens = allTokens.map((text) => ({ text, matched: sample.some((word) => text.includes(word)) }));
    return { title, matched: splitTokens.some((t) => t.matched), captured: undefined, splitTokens };
  }

  return {
    title,
    matched: match !== null,
    captured: props.mode === 'extract' ? match?.[1]?.trim() : undefined,
    splitTokens: undefined,
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
        <template v-else-if="mode === 'split'">
          <span v-if="r.splitTokens && r.splitTokens.length > 0" class="text-muted">
            → splits to
            <template v-for="(t, i) in r.splitTokens" :key="i">
              "<strong v-if="t.matched">{{ t.text }}</strong><template v-else>{{ t.text }}</template>"{{ i < r.splitTokens.length - 1 ? ', ' : '' }}
            </template>
          </span>
          <span v-else class="text-muted"> → nothing to split</span>
        </template>
      </span>
    </li>
  </ul>
</template>
