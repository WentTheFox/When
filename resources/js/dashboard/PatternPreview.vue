<script setup lang="ts">
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome';
import { faCheck, faXmark } from '@fortawesome/free-solid-svg-icons';
import { BButton } from 'bootstrap-vue-next';
import { computed, onMounted, onUnmounted, ref } from 'vue';

/**
 * The example text is a persisted, optional per-field override (v-model,
 * see the default-exported `model` below) — null means "use the hardcoded
 * `examples` prop", a stored string means the owner edited it away from
 * that, same optional-override shape every pattern field itself already
 * has. The default text is never sent to/compared against the server:
 * `examples` is a plain literal already baked into each call site
 * (Settings*.vue), so "has this been edited from the default" is a
 * client-only comparison against that same literal.
 */

/**
 * Editable multi-line live preview: each line of the textarea is one
 * example event title, tested against `pattern` as the owner types either
 * the pattern or the examples — so "what happens if I edit this example"
 * is answerable without leaving the settings page. Same overlay-on-top-
 * of-a-real-textarea trick as RegexPatternInput.vue (see that file's own
 * header comment for the full rationale): the native `<textarea>` handles
 * all real editing, its own text made transparent so the colored overlay
 * — which highlights exactly the *matched substring* of each line, not
 * the pattern's own syntax the way RegexPatternInput.vue's overlay does —
 * shows through in the same position. `white-space: pre` (no wrapping) on
 * both layers keeps each source line mapped to exactly one visual row in
 * both, the same reasoning RegexPatternInput.vue relies on for a single
 * line, just extended to many.
 *
 * The leading match/no-match icon lives in its own gutter column next to
 * the textarea+overlay, not inside the overlay text itself — mixing a
 * per-line icon into the overlay's own text would desync the overlay's
 * text content from the textarea's real text content, breaking the "same
 * text, different color" illusion the whole trick depends on.
 */
function tryExec(pattern: string, subject: string): RegExpExecArray | null {
  if (!pattern) return null;
  try {
    // 'd' (hasIndices) gives per-capture-group [start,end] via .indices —
    // needed to know exactly where group 1 falls inside the subject so it
    // can be highlighted on its own, not just whether it matched.
    return new RegExp(pattern, 'idu').exec(subject);
  } catch {
    return null;
  }
}

/** Same split-and-trim behavior as HighlightMatcher::matchTokens/App\Support\Regex::trySplit — see this file's previous version for the fuller comment. Fails closed to "the whole clause is one token" on an invalid split pattern. */
function splitIntoTokens(tokenStr: string, splitPattern: string): string[] {
  let rawTokens: string[];
  try {
    rawTokens = tokenStr.split(new RegExp(splitPattern || '[,&/]', 'iu'));
  } catch {
    rawTokens = [tokenStr];
  }
  return rawTokens.map((t) => t.trim()).filter((t) => t !== '');
}

const props = withDefaults(defineProps<{
  pattern: string | null;
  examples?: string[];
  placeholder?: string;
  /**
   * 'match': DND/nap/work/school/tentative/open-end/open-start — did it match at all?
   * 'extract': activity clause — what did group 1 capture, verbatim?
   * 'tokens': highlight clause — split group 1 on the split pattern, does any token contain a configured word (sampleWords)?
   * 'split': highlight name-split expression — highlights every resulting piece (no configured-word distinction — this field has no such concept), so an owner can see exactly how their clause gets divided.
   */
  mode: 'match' | 'extract' | 'tokens' | 'split';
  /** Used in 'tokens' mode only — stand-in for a share link's own configured highlight words. */
  sampleWords?: string[];
  /** Used in 'tokens'/'split' mode — the owner's highlight_split_pattern (or its default). */
  splitPattern?: string;
  showReset?: boolean;
}>(), {
  sampleWords: undefined,
  placeholder: undefined,
  splitPattern: undefined,
  showReset: true,
});

const DEFAULT_PLACEHOLDER = 'Type here to test pattern matching';

// `examples` is a fixed per-field literal (see every call site in
// Settings*.vue), never reassigned at runtime — defaultLinesText is just
// that joined into the same shape the textarea itself uses.
const defaultLinesText = props.examples?.join('\n') ?? '';

const model = defineModel<string | null>({ default: null });

// get/set computed rather than a plain ref: reading always falls back to
// the hardcoded default while nothing's been persisted; every edit writes
// straight through to the model (the parent's own form field), so it
// survives a save/reload instead of vanishing like this used to.
const linesText = computed({
  get: () => model.value ?? defaultLinesText,
  set: (value: string) => { model.value = value; },
});
const lines = computed(() => linesText.value.split('\n'));

type Span = { text: string; cls?: string };
type LineResult = { matched: boolean; spans: Span[] };

/**
 * Shrinks [start, end) to exclude any leading/trailing whitespace within
 * that substring — a regex match/capture can legitimately include a
 * surrounding space (e.g. a pattern like `\s*(?)$`), but underlining that
 * space along with the real matched text visually implies the space
 * itself is significant, which reads as confusing rather than
 * informative. Interior whitespace (inside the trimmed range) is left
 * alone — only the two edges are pulled in.
 */
function trimRange(line: string, start: number, end: number): [number, number] {
  while (start < end && /\s/.test(line[start]!)) start += 1;
  while (end > start && /\s/.test(line[end - 1]!)) end -= 1;
  return [start, end];
}

function highlightSpans(line: string, ranges: { start: number; end: number; cls: string }[]): Span[] {
  const sorted = [...ranges]
    .map((r) => {
      const [start, end] = trimRange(line, r.start, r.end);
      return { start, end, cls: r.cls };
    })
    .filter((r) => r.start < r.end)
    .sort((a, b) => a.start - b.start);
  const spans: Span[] = [];
  let cursor = 0;

  for (const range of sorted) {
    if (range.start < cursor) continue; // overlapping range from a bad pattern — skip rather than corrupt the slice
    if (range.start > cursor) spans.push({ text: line.slice(cursor, range.start) });
    spans.push({ text: line.slice(range.start, range.end), cls: range.cls });
    cursor = range.end;
  }
  if (cursor < line.length) spans.push({ text: line.slice(cursor) });

  return spans;
}

function resultFor(line: string): LineResult {
  if (props.mode === 'match') {
    const match = props.pattern ? tryExec(props.pattern, line) : null;
    if (!match) return { matched: false, spans: [{ text: line }] };
    return {
      matched: true,
      spans: highlightSpans(line, [{ start: match.index, end: match.index + match[0].length, cls: 'wtf-match-hit' }]),
    };
  }

  if (props.mode === 'extract') {
    const match = props.pattern ? tryExec(props.pattern, line) : null;
    const indices = (match as (RegExpExecArray & { indices?: Array<[number, number] | undefined> }) | null)?.indices;
    const group1 = indices?.[1];
    const spans = group1
      ? highlightSpans(line, [{ start: group1[0], end: group1[1], cls: 'wtf-match-capture' }])
      : [{ text: line }];
    return { matched: match !== null, spans };
  }

  if (props.mode === 'tokens') {
    const match = props.pattern ? tryExec(props.pattern, line) : null;
    const indices = (match as (RegExpExecArray & { indices?: Array<[number, number] | undefined> }) | null)?.indices;
    const group1 = indices?.[1];
    const words = match?.[1]
      ? (props.sampleWords ?? []).filter((word) => splitIntoTokens(match[1], props.splitPattern ?? '').some((token) => token.includes(word)))
      : [];
    const spans = group1
      ? highlightSpans(line, [{ start: group1[0], end: group1[1], cls: words.length > 0 ? 'wtf-match-hit' : 'wtf-match-capture' }])
      : [{ text: line }];
    return { matched: words.length > 0, spans };
  }

  // 'split' — every resulting piece is highlighted, not just ones that
  // happen to match a sample word (there's no "configured word" concept
  // for this field at all; the point of this preview is purely "how does
  // my clause get divided", not a match/no-match judgement per piece).
  const match = props.pattern ? tryExec(props.pattern, line) : null;
  const indices = (match as (RegExpExecArray & { indices?: Array<[number, number] | undefined> }) | null)?.indices;
  const group1 = indices?.[1];

  if (!group1 || !match?.[1]) return { matched: false, spans: [{ text: line }] };

  const clauseText = match[1];
  const clauseStart = group1[0];
  const tokens = splitIntoTokens(clauseText, props.splitPattern ?? '');

  const ranges: { start: number; end: number; cls: string }[] = [];
  let searchFrom = 0;
  for (const token of tokens) {
    const localIndex = clauseText.indexOf(token, searchFrom);
    if (localIndex === -1) continue;
    searchFrom = localIndex + token.length;
    ranges.push({ start: clauseStart + localIndex, end: clauseStart + localIndex + token.length, cls: 'wtf-match-capture' });
  }

  return { matched: ranges.length > 0, spans: highlightSpans(line, ranges) };
}

const results = computed(() => lines.value.map((line) => resultFor(line)));

function escapeHtml(s: string): string {
  return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

const highlightedHtml = computed(() =>
  results.value
    .map((r) => r.spans.map((s) => (s.cls ? `<span class="${s.cls}">${escapeHtml(s.text)}</span>` : escapeHtml(s.text))).join(''))
    .join('\n'),
);

// Same ResizeObserver-driven size sync as RegexPatternInput.vue, extended
// to both dimensions since this textarea resizes vertically (more/fewer
// example lines) rather than just horizontally.
const nativeEl = ref<HTMLTextAreaElement | null>(null);
const overlayEl = ref<HTMLDivElement | null>(null);
const overlaySize = ref<{ width: string; height: string }>({ width: '100%', height: '100%' });
let resizeObserver: ResizeObserver | null = null;

function updateOverlaySize(): void {
  if (!nativeEl.value) return;
  overlaySize.value = { width: `${nativeEl.value.offsetWidth}px`, height: `${nativeEl.value.offsetHeight}px` };
}

function syncScroll(): void {
  if (!nativeEl.value || !overlayEl.value) return;
  overlayEl.value.scrollTop = nativeEl.value.scrollTop;
  overlayEl.value.scrollLeft = nativeEl.value.scrollLeft;
}

onMounted(() => {
  updateOverlaySize();
  if (nativeEl.value) {
    resizeObserver = new ResizeObserver(updateOverlaySize);
    resizeObserver.observe(nativeEl.value);
  }
});
onUnmounted(() => resizeObserver?.disconnect());
</script>

<template>
  <div class="wtf-pattern-preview">
    <div class="wtf-pattern-preview-gutter">
      <div v-for="(r, i) in results" :key="i" class="wtf-pattern-preview-gutter-row" :class="r.matched ? 'wtf-pattern-preview-icon-match' : 'wtf-pattern-preview-icon-no-match'">
        <FontAwesomeIcon :icon="r.matched ? faCheck : faXmark" />
      </div>
    </div>
    <div class="wtf-pattern-preview-editor">
      <div
        ref="overlayEl"
        class="form-control wtf-pattern-preview-highlight"
        :style="{ width: overlaySize.width, height: overlaySize.height }"
        aria-hidden="true"
        v-html="highlightedHtml"
      />
      <textarea
        ref="nativeEl"
        v-model="linesText"
        :class="['form-control wtf-pattern-preview-native', { blank: linesText.length === 0 }]"
        :rows="Math.max(lines.length, 2)"
        spellcheck="false"
        autocomplete="off"
        autocapitalize="off"
        :placeholder="placeholder ?? DEFAULT_PLACEHOLDER"
        @scroll="syncScroll"
        @input="syncScroll"
      />
    </div>
  </div>
  <BButton
    v-if="showReset"
    variant="link"
    size="sm"
    class="p-0 align-baseline mt-1"
    :disabled="model === null"
    @click="model = null"
  >
    Reset examples
  </BButton>
</template>
