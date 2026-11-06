import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import { faIconFor } from '../icon-palette';

// app/Support/IconKey.php is the source of truth for which keys exist; the TS map must have a glyph for each.
const phpKeys = [...readFileSync(resolve(__dirname, '../../../../app/Support/IconKey.php'), 'utf8').matchAll(/^\s+case \w+ = '([a-z0-9-]+)';/gm)].map((m) => m[1]);

describe('icon palette', () => {
  it('reads a plausible number of keys out of IconKey.php', () => {
    expect(phpKeys.length).toBeGreaterThan(100);
    expect(new Set(phpKeys).size).toBe(phpKeys.length);
  });

  it.each(phpKeys)('has a glyph for %s', (key) => {
    expect(faIconFor(key)).toBeDefined();
  });

  it('draws the graded signal bars with a different path per level', () => {
    const paths = ['signal-0', 'signal-1', 'signal-2', 'signal-3'].map((key) => faIconFor(key)!.icon[4]);

    expect(new Set(paths).size).toBe(4);
  });
});
