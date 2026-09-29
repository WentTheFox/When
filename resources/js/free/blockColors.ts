import { addDays, subDays } from 'date-fns';
import { getBlocksForDay, isTentativeEndDisplay, isTentativeStartDisplay, lastOf } from './nuxt-blocks';
import type { DayBlock, EventSlot } from './nuxt-blocks';
import { resolveSwatchHex } from './color-palette';
import { BLOCK_ALPHA, hexToRgba } from './color-utils';

/**
 * Maps a block's type to the CSS custom property that carries the owner's
 * global (non-overridden) color for that type — CalendarView.vue's own
 * dark-theme.css counterparts (--app-color-free/busy/highlighted/etc.).
 */
export const BLOCK_TYPE_COLOR_VAR: Record<DayBlock['type'], string> = {
  free: '--app-color-free',
  unavailable: '--app-color-busy',
  highlighted: '--app-color-highlighted',
  work: '--app-color-work',
  school: '--app-color-school',
  public: '--app-color-public',
  sleep: '--app-color-sleep',
};

/**
 * Only `highlighted`/`public` blocks can carry a per-block
 * App\Models\ActivityLocalization role's own color_key override (see
 * App\Services\Calendar\HighlightMatcher::matchClauseText) — resolved to
 * both a plain hex (for --app-hue-<type> and the color-mix() text-tint
 * formula) and the theme-appropriate rgba() literal (for --app-color-
 * <type>), matching dark-theme.css's own per-theme block alphas exactly
 * (see BLOCK_ALPHA). Returns undefined when the block has no override, so
 * every caller falls back to the block's plain type-level color the same
 * way.
 */
export function activityColorOverride(block: DayBlock, theme: 'light' | 'dark'): { hex: string; rgba: string } | undefined {
  if ((block.type !== 'highlighted' && block.type !== 'public') || !block.activityColor) return undefined;
  const hex = resolveSwatchHex(block.activityColor, block.type, theme);
  return { hex, rgba: hexToRgba(hex, BLOCK_ALPHA[theme][block.type]) };
}

/**
 * Inline style for a block's own DOM element that paints it with its
 * activityColor override, or undefined when it has none — more involved
 * than a simple prop swap because color isn't one custom property: the
 * block's background (--app-color-highlighted) and its label's text tint
 * (--app-fcal-text-highlighted) are two SEPARATE custom properties, and
 * per CLAUDE.md's own documented gotcha, a var() nested inside another
 * custom property's value resolves against the scope where that property
 * was DECLARED, not where it's used — --app-fcal-text-highlighted's
 * color-mix() formula is declared once at :root referencing
 * --app-hue-highlighted, so overriding only --app-hue-highlighted here
 * would never actually change the label's tint. Redeclaring all three
 * locally (mirroring Free/Show.vue's rootStyle formula for one swatch
 * instead of the whole page) sidesteps that.
 *
 * The fade gradient a *neighboring* block renders (see blockFadeColor
 * below) needs its own, separate handling despite reading
 * var(--app-color-<type>) by name: an inline custom property set here
 * only cascades to this block's own descendants, never sideways to a
 * neighboring block's element — the same custom-property-scoping gotcha
 * as above, just in the sibling direction instead of the nested one.
 */
export function activityColorStyle(block: DayBlock, theme: 'light' | 'dark'): Record<string, string> | undefined {
  const override = activityColorOverride(block, theme);
  if (!override) return undefined;
  return {
    [`--app-color-${block.type}`]: override.rgba,
    [`--app-hue-${block.type}`]: override.hex,
    [`--app-fcal-text-${block.type}`]: `color-mix(in srgb, ${override.hex} 65%, var(--app-text) 35%)`,
  };
}

/**
 * The color a neighboring block's fade gradient should blend toward: the
 * block's own resolved activityColor override (a literal rgba(), since a
 * var() reference can't reach across to a sibling element — see
 * activityColorStyle's doc comment) when it has one, falling back to the
 * existing var(--app-color-<type>) reference (which correctly tracks the
 * owner's global default, including live theme/customization changes)
 * when it doesn't.
 */
export function blockFadeColor(block: DayBlock, theme: 'light' | 'dark'): string {
  return activityColorOverride(block, theme)?.rgba ?? `var(${BLOCK_TYPE_COLOR_VAR[block.type]})`;
}

// Where two fuzzy edges meet, both sides fade to the same midpoint color, so
// the seam is one continuous gradient instead of two clashing ones.
function seamColor(a: string, b: string): string {
  return `color-mix(in srgb, ${a} 50%, ${b})`;
}

/**
 * The --fade-start/--fade-end custom properties for a tentative block's
 * edge gradients, shared by CalendarView.vue and AgendaView.vue so both
 * views blend (including activityColor overrides, via blockFadeColor)
 * identically.
 *
 * Blocks tile the day with no gaps, so the previous/next array entry is the
 * immediately-adjacent block in time. Only an edge that's actually fuzzy
 * (tentativeStart/tentativeEnd, independently) gets a gradient at all — the
 * other edge renders as a hard line at its own solid color. For a run of
 * consecutive tentative blocks, each block's bottom edge still fades toward
 * the next block's color — but the block below never fades in at its own
 * top when its predecessor's bottom edge is also fuzzy, so a shared seam
 * only ever fades once (attributed to the block above), not twice meeting
 * in the middle. That was a previous bug: both sides independently faded
 * toward each other's nominal color, producing a mismatched double-fade
 * "pinch" at every internal boundary instead of one continuous cascade
 * down the run.
 *
 * At the very top/bottom of a day's own blocks, the neighbor carries over
 * from the previous/next calendar day's last/first block, computed
 * directly rather than looked up in the rendered day list — the visible
 * range can trim a day (e.g. past-day filtering on the current week)
 * while the API still returns that day's data, padded a day either side
 * of the requested range — falling back to transparent only where there's
 * truly no data for the adjacent day (a hard, non-fuzzy edge never falls
 * back to transparent, since it always renders its own solid color).
 */
export function tentativeFadeStyle(
  day: Date,
  blocks: DayBlock[],
  i: number,
  events: EventSlot[],
  timezone: string,
  theme: 'light' | 'dark',
): Record<string, string> {
  const block = blocks[i]!;
  const startFuzzy = isTentativeStartDisplay(block);
  const endFuzzy = isTentativeEndDisplay(block);
  if (!startFuzzy && !endFuzzy) return {};

  const style: Record<string, string> = {};

  if (startFuzzy) {
    const prev = i > 0
      ? blocks[i - 1]
      : lastOf(getBlocksForDay(subDays(day, 1), events, timezone));
    if (prev) {
      style['--fade-start'] = isTentativeEndDisplay(prev)
        ? seamColor(blockFadeColor(prev, theme), blockFadeColor(block, theme))
        : blockFadeColor(prev, theme);
    }
  } else {
    style['--fade-start'] = blockFadeColor(block, theme);
  }

  if (endFuzzy) {
    const next = i < blocks.length - 1
      ? blocks[i + 1]
      : getBlocksForDay(addDays(day, 1), events, timezone)[0];
    if (next) {
      style['--fade-end'] = isTentativeStartDisplay(next)
        ? seamColor(blockFadeColor(block, theme), blockFadeColor(next, theme))
        : blockFadeColor(next, theme);
    }
  } else {
    style['--fade-end'] = blockFadeColor(block, theme);
  }

  return style;
}
