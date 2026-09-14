import type { DayBlock } from './nuxt-blocks';
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
