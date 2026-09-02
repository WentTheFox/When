<script setup lang="ts">
import { computed, ref } from 'vue';

/**
 * Lightweight syntax-highlighted editor for the delimiter-free regex
 * *fragments* used throughout Settings.vue's "Event title matching rules"
 * section (see PatternPreview.vue's own header comment for what these
 * fragments actually are — no `/.../` delimiters, the app wraps them
 * itself). A real regex-aware editor (CodeMirror et al.) is overkill for
 * eight small single/two-line fields; this is the standard "invisible
 * native input/textarea on top of a matching highlighted overlay
 * underneath" trick instead — the native element keeps all real text-
 * editing behavior (caret, selection, IME, undo, a11y) and its own
 * `.form-control` chrome (border/background/focus ring), while its own
 * text is rendered transparent so the colored overlay text shows through
 * in exactly the same position. Zero new dependencies.
 */

const props = withDefaults(
  defineProps<{
    id: string;
    modelValue: string | null;
    placeholder?: string;
    multiline?: boolean;
    rows?: number;
  }>(),
  {
    placeholder: undefined,
    multiline: false,
    rows: 2,
  },
);

const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

const nativeEl = ref<HTMLInputElement | HTMLTextAreaElement | null>(null);
const highlightEl = ref<HTMLDivElement | null>(null);

const text = computed(() => props.modelValue ?? '');

function onInput(event: Event) {
  const target = event.target as HTMLInputElement | HTMLTextAreaElement;
  emit('update:modelValue', target.value);
  syncScroll();
}

function syncScroll() {
  if (!nativeEl.value || !highlightEl.value) return;
  highlightEl.value.scrollTop = nativeEl.value.scrollTop;
  highlightEl.value.scrollLeft = nativeEl.value.scrollLeft;
}

type Token = { text: string; cls?: string };

/**
 * Tokenizes just enough to color the metacharacter set called out in this
 * section's own help text (\ ^ $ . | ? * + ( ) [ ] { }) plus capture-group
 * parens and character-class brackets. Not a full regex parser — e.g.
 * everything between an unescaped `[` and the next `]` is treated as inert
 * (matching how little most of those characters mean once inside a
 * class) — just enough structure for an owner to visually parse their own
 * pattern at a glance.
 *
 * `(?:…)` — a non-capturing group — gets its own color, distinct from a
 * real `(…)` capture group: the Highlight/Activity fields (see the "What
 * these text-match fields actually do" alert above) require exactly one
 * real capture group, so telling the two apart at a glance matters here.
 * The whole opening delimiter is colored as one token (not just the `(`),
 * and — since groups can nest — a stack of what each currently-open `(`
 * actually was is what lets a `)` be colored to match its own opener
 * rather than always defaulting to the plain capturing-group color.
 */
function tokenize(pattern: string): Token[] {
  const tokens: Token[] = [];
  let plain = '';
  let inClass = false;
  const groupStack: ('cap' | 'noncap')[] = [];

  const flushPlain = () => {
    if (plain) {
      tokens.push({ text: plain });
      plain = '';
    }
  };

  let i = 0;
  while (i < pattern.length) {
    const ch = pattern[i];

    if (ch === '\\' && i + 1 < pattern.length) {
      flushPlain();
      tokens.push({ text: pattern.slice(i, i + 2), cls: 'wtf-regex-tok-escape' });
      i += 2;
      continue;
    }

    if (inClass) {
      if (ch === ']') {
        flushPlain();
        tokens.push({ text: ch, cls: 'wtf-regex-tok-class' });
        inClass = false;
      } else {
        plain += ch;
      }
      i += 1;
      continue;
    }

    if (ch === '[') {
      flushPlain();
      tokens.push({ text: ch, cls: 'wtf-regex-tok-class' });
      inClass = true;
      i += 1;
      continue;
    }

    if (ch === '(') {
      flushPlain();
      if (pattern.slice(i, i + 3) === '(?:') {
        tokens.push({ text: '(?:', cls: 'wtf-regex-tok-noncap' });
        groupStack.push('noncap');
        i += 3;
      } else {
        tokens.push({ text: ch, cls: 'wtf-regex-tok-group' });
        groupStack.push('cap');
        i += 1;
      }
      continue;
    }

    if (ch === ')') {
      flushPlain();
      const kind = groupStack.pop();
      tokens.push({ text: ch, cls: kind === 'noncap' ? 'wtf-regex-tok-noncap' : 'wtf-regex-tok-group' });
      i += 1;
      continue;
    }

    if ('^$.|?*+{}'.includes(ch)) {
      flushPlain();
      tokens.push({ text: ch, cls: 'wtf-regex-tok-meta' });
      i += 1;
      continue;
    }

    plain += ch;
    i += 1;
  }

  flushPlain();
  return tokens;
}

function escapeHtml(s: string): string {
  return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

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

const highlightedHtml = computed(() => {
  const html = tokenize(displayText.value)
    .map((t) => (t.cls ? `<span class="${t.cls}">${escapeHtml(t.text)}</span>` : escapeHtml(t.text)))
    .join('');

  // A trailing "\n" would otherwise render at the same height as no
  // trailing newline at all, unlike a real <textarea> — pad it so the
  // overlay always reserves the same line count the native element does.
  return (displayText.value.endsWith('\n') ? `${html}&nbsp;` : html) || '&nbsp;';
});
</script>

<template>
  <div class="wtf-regex-editor" :class="{ 'wtf-regex-editor-multiline': multiline }">
    <div
      ref="highlightEl"
      class="form-control wtf-regex-highlight"
      :class="{ 'wtf-regex-placeholder': showingPlaceholder }"
      aria-hidden="true"
      v-html="highlightedHtml"
    />
    <textarea
      v-if="multiline"
      :id="id"
      ref="nativeEl"
      class="form-control wtf-regex-native"
      :rows="rows"
      :placeholder="placeholder"
      :value="text"
      spellcheck="false"
      autocomplete="off"
      autocapitalize="off"
      @input="onInput"
      @scroll="syncScroll"
    />
    <input
      v-else
      :id="id"
      ref="nativeEl"
      type="text"
      class="form-control wtf-regex-native"
      :placeholder="placeholder"
      :value="text"
      spellcheck="false"
      autocomplete="off"
      autocapitalize="off"
      @input="onInput"
      @scroll="syncScroll"
    />
  </div>
</template>
