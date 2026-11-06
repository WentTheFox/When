import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import { paletteIconFor } from '../icon-palette';

// app/Support/IconKey.php is the source of truth for which keys exist; the TS map must have a glyph for each.
const phpKeys = [...readFileSync(resolve(__dirname, '../../../../app/Support/IconKey.php'), 'utf8').matchAll(/^\s+case \w+ = '([a-z0-9-]+)';/gm)].map((m) => m[1]);

describe('icon palette', () => {
  it('reads a plausible number of keys out of IconKey.php', () => {
    expect(phpKeys.length).toBeGreaterThan(100);
    expect(new Set(phpKeys).size).toBe(phpKeys.length);
  });

  it.each(phpKeys)('has a glyph for %s', (key) => {
    expect(paletteIconFor(key)).toBeDefined();
  });

  it('gives each graded signal level its own glyph', () => {
    const glyphs = ['signal-0', 'signal-1', 'signal-2', 'signal-3', 'signal'].map((key) => paletteIconFor(key));

    expect(new Set(glyphs).size).toBe(5);
  });

  it('draws the traffic light and checkered flag locally, since Lucide has neither', () => {
    expect(paletteIconFor('traffic-light')).toBeDefined();
    expect(paletteIconFor('flag-checkered')).toBeDefined();
  });
});
