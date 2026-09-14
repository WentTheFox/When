import { beforeAll, describe, expect, it } from 'vitest';
import { activityColorOverride, activityColorStyle, blockFadeColor, BLOCK_TYPE_COLOR_VAR } from '../blockColors';
import { setColorPalette, type ColorSwatch } from '../color-palette';
import type { DayBlock } from '../nuxt-blocks';
import { BLOCK_ALPHA, hexToRgba } from '../color-utils';

const RED: ColorSwatch = { key: 'red', label: 'Red', light: '#ff0000', dark: '#cc0000' };
const BLUE: ColorSwatch = { key: 'blue', label: 'Blue', light: '#0000ff', dark: '#0000cc' };

beforeAll(() => {
  setColorPalette([RED, BLUE], {
    accent: 'blue', secondary: 'blue', free: 'blue', busy: 'blue',
    work: 'blue', school: 'blue', public: 'blue', sleep: 'blue', highlighted: 'blue',
  });
});

function block(overrides: Partial<DayBlock> = {}): DayBlock {
  return {
    topPct: 0,
    heightPct: 10,
    startTime: '09:00',
    endTime: '10:00',
    type: 'highlighted',
    ...overrides,
  };
}

describe('activityColorOverride', () => {
  it('is undefined for a block with no activityColor', () => {
    expect(activityColorOverride(block({ activityColor: null }), 'dark')).toBeUndefined();
  });

  it('is undefined for a block type that cannot carry an override, even with activityColor set', () => {
    expect(activityColorOverride(block({ type: 'unavailable', activityColor: 'red' }), 'dark')).toBeUndefined();
  });

  it('resolves a highlighted block\'s override to the theme-appropriate hex and rgba', () => {
    const result = activityColorOverride(block({ type: 'highlighted', activityColor: 'red' }), 'dark');
    expect(result?.hex).toBe('#cc0000');
    expect(result?.rgba).toBe(hexToRgba('#cc0000', BLOCK_ALPHA.dark.highlighted));
  });

  it('resolves a public block\'s override the same way', () => {
    const result = activityColorOverride(block({ type: 'public', activityColor: 'red' }), 'light');
    expect(result?.hex).toBe('#ff0000');
    expect(result?.rgba).toBe(hexToRgba('#ff0000', BLOCK_ALPHA.light.public));
  });
});

describe('activityColorStyle', () => {
  it('is undefined when the block has no override', () => {
    expect(activityColorStyle(block({ activityColor: null }), 'dark')).toBeUndefined();
  });

  it('sets all three custom properties from the resolved override', () => {
    const style = activityColorStyle(block({ type: 'highlighted', activityColor: 'red' }), 'dark');
    expect(style).toEqual({
      '--app-color-highlighted': hexToRgba('#cc0000', BLOCK_ALPHA.dark.highlighted),
      '--app-hue-highlighted': '#cc0000',
      '--app-fcal-text-highlighted': 'color-mix(in srgb, #cc0000 65%, var(--app-text) 35%)',
    });
  });
});

describe('blockFadeColor', () => {
  it('falls back to the type-level CSS var when the block has no override', () => {
    expect(blockFadeColor(block({ type: 'unavailable', activityColor: null }), 'dark'))
      .toBe(`var(${BLOCK_TYPE_COLOR_VAR.unavailable})`);
  });

  it('falls back to the type-level CSS var for a highlighted/public block with no override', () => {
    expect(blockFadeColor(block({ type: 'highlighted', activityColor: null }), 'dark'))
      .toBe(`var(${BLOCK_TYPE_COLOR_VAR.highlighted})`);
  });

  /**
   * The actual bug this module was extracted to fix: a neighboring block's
   * fade previously always read `var(--app-color-<type>)` — the owner's
   * global default for that type — even when the block it's fading toward
   * has its own activityColor override. It must resolve to the literal
   * override color instead, since a var() set inline on that block's own
   * element can't be read from a sibling's gradient.
   */
  it('resolves to the literal override color, not the type-level CSS var, when the block has one', () => {
    const overridden = block({ type: 'highlighted', activityColor: 'red' });
    const result = blockFadeColor(overridden, 'dark');
    expect(result).toBe(hexToRgba('#cc0000', BLOCK_ALPHA.dark.highlighted));
    expect(result).not.toContain('var(');
  });

  it('matches exactly what activityColorStyle paints that same block with', () => {
    const overridden = block({ type: 'public', activityColor: 'blue' });
    const fade = blockFadeColor(overridden, 'light');
    const style = activityColorStyle(overridden, 'light');
    expect(fade).toBe(style!['--app-color-public']);
  });
});
