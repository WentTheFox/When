import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

/**
 * Buttons rendered right next to each other with nothing between them look
 * cramped. Give the second one a margin (ms-2), or put them in a flex
 * container with a gap, a .btn-group / BButtonGroup, or an .input-group.
 *
 * This is a source-level heuristic: it looks at consecutive <BButton> /
 * <button> elements in the .vue templates, so a new offender fails here rather
 * than getting past review.
 */

const ROOT = join(__dirname, '..');
const BUTTON_START = /<(BButton|button)\b/;
/** Anything in or just above the pair that already separates them (or deliberately joins them). */
const SPACED = /\b(ms|me|mx|ps|pe)-\d|\bgap-\d|btn-group|BButtonGroup|input-group|justify-content-between|style="gap|btn-close/;

function vueFiles(dir: string): string[] {
  return readdirSync(dir).flatMap((name) => {
    const path = join(dir, name);
    if (name === 'node_modules' || name === '__tests__') return [];
    return statSync(path).isDirectory() ? vueFiles(path) : path.endsWith('.vue') ? [path] : [];
  });
}

/** [firstLine, lastLine] (0-based) of every button element, found naively by its closing tag. */
function buttonSpans(lines: string[]): [number, number][] {
  const spans: [number, number][] = [];

  for (let i = 0; i < lines.length; i++) {
    const match = BUTTON_START.exec(lines[i]);
    if (!match || /^\s*(\/\/|\*)/.test(lines[i])) continue;

    const close = `</${match[1]}>`;
    let end = i;
    while (end < lines.length && !lines[end].includes(close)) end++;

    spans.push([i, end]);
    i = end;
  }

  return spans;
}

function offenders(source: string): number[] {
  const lines = source.split('\n');
  const spans = buttonSpans(lines);
  const found: number[] = [];

  for (let k = 1; k < spans.length; k++) {
    const [firstStart, firstEnd] = spans[k - 1];
    const [start, end] = spans[k];

    if (start - firstEnd > 2) continue; // not neighbours

    const second = lines.slice(start, end + 1).join('\n');
    // Looks a little way above the first button too: a wrapper's `gap-2` sits above a multi-line button, not beside it.
    const context = lines.slice(Math.max(0, firstStart - 12), start + 1).join('\n');

    if (!SPACED.test(second + context)) found.push(start + 1);
  }

  return found;
}

describe('button spacing', () => {
  it('flags two neighbouring buttons with nothing between them (the heuristic works)', () => {
    const cramped = '<div>\n  <BButton>Save</BButton>\n  <BButton>Delete</BButton>\n</div>';
    expect(offenders(cramped)).toEqual([3]);
  });

  it.each([
    ['a margin', '<div>\n  <BButton>Save</BButton>\n  <BButton class="ms-2">Delete</BButton>\n</div>'],
    ['a flex gap', '<div class="d-flex gap-2">\n  <BButton>Save</BButton>\n  <BButton>Delete</BButton>\n</div>'],
    ['a button group', '<BButtonGroup>\n  <BButton>A</BButton>\n  <BButton>B</BButton>\n</BButtonGroup>'],
    ['an input group', '<div class="input-group">\n  <BFormInput />\n  <BButton>Go</BButton>\n  <BButton>Stop</BButton>\n</div>'],
  ])('accepts neighbours separated by %s', (_name, source) => {
    expect(offenders(source)).toEqual([]);
  });

  it('has no cramped neighbouring buttons in any template', () => {
    const problems = vueFiles(ROOT).flatMap((file) =>
      offenders(readFileSync(file, 'utf8')).map((line) => `${file.slice(ROOT.length + 1)}:${line}`),
    );

    expect(problems, `Add ms-2 (or a flex gap / button group) between these neighbouring buttons:\n${problems.join('\n')}`).toEqual([]);
  });
});
