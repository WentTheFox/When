/**
 * Shared tokenizer behind both RegexPatternInput.vue's live editor overlay
 * and RegexHighlightedCode.vue's read-only Suggested/Default `<code>`
 * spans — same wtf-regex-tok-* classes (see dark-theme.css) either way, so
 * a pattern reads identically whether it's sitting in the input or shown
 * as this field's own suggested starting value.
 */

export type RegexToken = { text: string; cls?: string };

/**
 * Tokenizes just enough to color the metacharacter set called out in the
 * "What these text-match fields actually do" crash course (Settings.vue):
 * \ ^ $ . | ? * + ( ) [ ] { }, plus capture-group parens and character-
 * class brackets. Not a full regex parser — e.g. everything between an
 * unescaped `[` and the next `]` is treated as inert (matching how little
 * most of those characters mean once inside a class) — just enough
 * structure for an owner to visually parse their own pattern at a glance.
 *
 * `(?:…)` — a non-capturing group — gets its own color, distinct from a
 * real `(…)` capture group: the Highlight/Activity fields require exactly
 * one real capture group, so telling the two apart at a glance matters
 * here. The whole opening delimiter is colored as one token (not just the
 * `(`), and — since groups can nest — a stack of what each currently-open
 * `(` actually was is what lets a `)` be colored to match its own opener
 * rather than always defaulting to the plain capturing-group color.
 *
 * A `\` escape only gets its own color when the character after it is a
 * genuine *shorthand* with meaning beyond "match this one character
 * literally" (`\d` "digit", `\w` "word char", `\s` "whitespace", `\b` word
 * boundary, `\n`/`\r`/`\t` control chars, `\1`/`\2`… backreferences —
 * CONTROL_ESCAPE_CHARS below). `\?`, `\[`, `\]`, `\}`, `\.` and so on are
 * just a metacharacter stripped of its special meaning so it matches
 * itself — visually that's plain text, not a distinct regex "feature", so
 * it renders as plain text too (the leading `\` included, since dropping
 * it from the display would misrepresent what's actually stored).
 */
const CONTROL_ESCAPE_CHARS = /[dDwWsSbBnrtfv0-9]/;

export function tokenizePattern(pattern: string): RegexToken[] {
  const tokens: RegexToken[] = [];
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
      const next = pattern[i + 1];
      if (CONTROL_ESCAPE_CHARS.test(next)) {
        flushPlain();
        tokens.push({ text: pattern.slice(i, i + 2), cls: 'wtf-regex-tok-escape' });
      } else {
        plain += pattern.slice(i, i + 2);
      }
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

export function escapeHtml(s: string): string {
  return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

export function highlightPatternHtml(pattern: string): string {
  const html = tokenizePattern(pattern)
    .map((t) => (t.cls ? `<span class="${t.cls}">${escapeHtml(t.text)}</span>` : escapeHtml(t.text)))
    .join('');

  return html || '&nbsp;';
}
