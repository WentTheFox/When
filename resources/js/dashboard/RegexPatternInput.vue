<script setup lang="ts">
import { faPuzzlePiece } from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome';
import { BButton } from 'bootstrap-vue-next';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { requestRegexEdit } from './regexEditorModal';
import { highlightPatternHtml } from './regexHighlight';
import { countCapturingGroups, findUnsatisfiableBranches, parsePatternToAst } from './regex-editor/regexAstModel';
import type { PatternPreviewConfig } from './patternPreviewTypes';

/**
 * Lightweight syntax-highlighted editor for the delimiter-free regex
 * *fragments* used throughout Settings.vue's "Event title matching rules"
 * section (see PatternPreview.vue's own header comment for what these
 * fragments actually are — no `/.../` delimiters, the app wraps them
 * itself). A real regex-aware editor (CodeMirror et al.) is overkill for
 * eight small fields; this is the standard "invisible native textarea on
 * top of a matching highlighted overlay underneath" trick instead — the
 * native element keeps all real text-editing behavior (caret, selection,
 * IME, undo, a11y) and its own `.form-control` chrome (border/background/
 * focus ring), while its own text is rendered transparent so the colored
 * overlay text shows through in exactly the same position. Zero new
 * dependencies.
 *
 * Always a <textarea>, not an <input> — every one of these fields is
 * still logically a single line (a newline is stripped on input, see
 * onInput below), but a <textarea> is what gives the native
 * `resize: horizontal` handle a plain single-line input never has, so an
 * owner can drag a field wider to see a long pattern in full instead of
 * scrolling it horizontally a few characters at a time.
 */

const props = withDefaults(
  defineProps<{
    id: string;
    modelValue: string | null;
    placeholder?: string;
    /** Plain-text field name (e.g. "DND event regular expression") shown in the visual editor modal's own title, so it's clear which field a pattern being built there applies to. */
    fieldLabel: string;
    /** The same config driving whichever PatternPreview is rendered next to this field on the page — see patternPreviewTypes.ts. Forwarded to the visual editor modal so its own preview is that exact same component/config, not a second hand-rolled one. */
    previewConfig: PatternPreviewConfig;
    /**
     * Both a cap the visual editor enforces while building a pattern (see
     * regexEditorModal.ts's own doc comment) AND the exact count this
     * field's own hand-typed value is validated against below — every
     * current caller passes this because the server requires EXACTLY this
     * many real capture groups (App\Support\Regex::validateSingleCaptureGroup),
     * not merely "no more than this many". Leave unset for a field with no
     * such server-side rule.
     */
    maxCaptureGroups?: number;
  }>(),
  {
    placeholder: undefined,
    maxCaptureGroups: undefined,
  },
);

const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

/** The field's own PatternPreview v-model (its persisted example-lines override) — kept in sync so the modal's preview starts from, and Apply writes back to, the same example text as the on-page preview. */
const previewModelValue = defineModel<string | null>('previewModelValue', { default: null });

const nativeEl = ref<HTMLTextAreaElement | null>(null);
const highlightEl = ref<HTMLDivElement | null>(null);

const text = computed(() => props.modelValue ?? '');

// Forced single-line: a newline can still reach `value` via a paste (a
// keydown.enter.prevent on the template only stops the Enter *key*), so
// this strips \r/\n unconditionally on every input event rather than
// trying to intercept every way one could get in.
function onInput(event: Event) {
  const target = event.target as HTMLTextAreaElement;
  const sanitized = target.value.replace(/[\r\n]+/g, '');
  emit('update:modelValue', sanitized);
  syncScroll();
}

function syncScroll() {
  if (!nativeEl.value || !highlightEl.value) return;
  highlightEl.value.scrollTop = nativeEl.value.scrollTop;
  highlightEl.value.scrollLeft = nativeEl.value.scrollLeft;
}

// The overlay is a plain <div>, not itself resizable — it has to mirror
// whatever box size the owner just dragged the native textarea to (via
// its `resize: horizontal` handle) explicitly, rather than via CSS alone:
// the wrapping .wtf-regex-editor doesn't grow just because a resizable
// child inside it got wider (a resize handle changes that one element's
// own box, not its parent's layout), so an absolutely-positioned
// `inset: 0` overlay would stay clipped to the ORIGINAL width while the
// native element beneath it kept growing.
const overlaySize = ref<{ width: string; height: string }>({ width: '100%', height: '100%' });
let resizeObserver: ResizeObserver | null = null;

function updateOverlaySize(): void {
  if (!nativeEl.value) return;
  overlaySize.value = {
    width: `${nativeEl.value.offsetWidth}px`,
    height: `${nativeEl.value.offsetHeight}px`,
  };
}

onMounted(() => {
  updateOverlaySize();
  if (nativeEl.value) {
    resizeObserver = new ResizeObserver(updateOverlaySize);
    resizeObserver.observe(nativeEl.value);
  }
});

onUnmounted(() => resizeObserver?.disconnect());

// A blank field shows the placeholder, tokenized the same as real text
// (dimmed via wtf-regex-placeholder below) rather than the native
// element's own plain, unstyled ::placeholder — that pseudo-element is
// explicitly suppressed in CSS so this is the only copy that renders,
// otherwise both would show at once. Without this, every field with a
// placeholder (highlight/tentative/open-end/open-start) looked like it
// hadn't gotten the highlighting treatment at all until something was
// actually typed into it.
const showingPlaceholder = computed(() => text.value === '' && !!props.placeholder);
const displayText = computed(() => (showingPlaceholder.value ? props.placeholder! : text.value));

const highlightedHtml = computed(() => highlightPatternHtml(displayText.value));

/**
 * Client-side echo of the two structural checks App\Support\Regex /
 * UpdateSettingsRequest enforce server-side on save — surfaced here too
 * since a pattern can just as easily be hand-typed straight into this
 * textarea as built via the visual editor modal (which only prevents
 * *adding* a capture group past the cap; it can't stop someone from
 * typing a pattern with too few, or hand-editing one to drop below it).
 * Blank is never flagged: every field here treats it as an intentional
 * "use the default" or "off" state, not an error.
 * 1. maxCaptureGroups, when set, isn't just an upper bound in practice —
 *    both real fields that pass it (highlight_clause_pattern,
 *    activity_clause_pattern) require EXACTLY that many real capture
 *    groups server-side (validateSingleCaptureGroup), so a count below it
 *    is just as invalid as one above.
 * 2. findUnsatisfiableBranches (regexAstModel.ts) — a ^/$ placed where
 *    required content still comes before/after it, making the whole
 *    pattern (or one alternative of it) impossible to ever match.
 */
const validationMessage = computed(() => {
  if (text.value === '') return null;
  const ast = parsePatternToAst(text.value);

  if (props.maxCaptureGroups !== undefined) {
    const count = countCapturingGroups(ast);
    if (count !== props.maxCaptureGroups) {
      const need = `exactly ${props.maxCaptureGroups} capture group${props.maxCaptureGroups === 1 ? '' : 's'}`;
      return count === 0 ? `This field requires ${need} — none found.` : `This field requires ${need} — found ${count}.`;
    }
  }

  if (findUnsatisfiableBranches(ast).size > 0) {
    return 'This pattern can never match anything — check where ^ or $ are placed.';
  }

  return null;
});
const isInvalid = computed(() => validationMessage.value !== null);

async function openVisualEditor(): Promise<void> {
  const result = await requestRegexEdit({
    pattern: text.value,
    fieldLabel: props.fieldLabel,
    previewConfig: props.previewConfig,
    previewModelValue: previewModelValue.value,
    maxCaptureGroups: props.maxCaptureGroups,
  });
  if (result === null) return;
  emit('update:modelValue', result.pattern);
  previewModelValue.value = result.previewModelValue;
}
</script>

<template>
  <div class="wtf-regex-input-group">
    <div class="wtf-regex-editor">
      <div
        ref="highlightEl"
        class="form-control wtf-regex-highlight"
        :class="{ 'wtf-regex-placeholder': showingPlaceholder }"
        :style="{ width: overlaySize.width, height: overlaySize.height }"
        aria-hidden="true"
        v-html="highlightedHtml"
      />
      <textarea
        :id="id"
        ref="nativeEl"
        class="form-control wtf-regex-native"
        :class="{ 'is-invalid': isInvalid }"
        rows="1"
        :placeholder="placeholder"
        :value="text"
        spellcheck="false"
        autocomplete="off"
        autocapitalize="off"
        @input="onInput"
        @scroll="syncScroll"
        @keydown.enter.prevent
      />
    </div>
    <BButton
      variant="outline-secondary"
      size="sm"
      class="wtf-regex-visual-editor-btn"
      title="Open visual editor"
      aria-label="Open visual editor"
      @click="openVisualEditor"
    >
      <FontAwesomeIcon :icon="faPuzzlePiece" />
    </BButton>
  </div>
  <div v-if="validationMessage" class="invalid-feedback d-block">{{ validationMessage }}</div>
</template>
