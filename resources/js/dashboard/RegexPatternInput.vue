<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { highlightPatternHtml } from './regexHighlight';

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
  }>(),
  {
    placeholder: undefined,
  },
);

const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

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
</script>

<template>
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
</template>
