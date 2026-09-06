/**
 * <input type="color"> (Settings.vue's accent/free/busy/sleep/highlighted
 * pickers) only ever produces a solid "#RRGGBB" hex — no alpha channel.
 * Binding that straight to --app-color-free/busy/highlighted/sleep would
 * make an owner's customized blocks fully opaque, losing the transparent-
 * wash treatment every default color gets (see dark-theme.css's --app-
 * color-* — verified against the reference site's own computed styles).
 * This re-applies the same alpha the built-in dark-theme default uses, so
 * a custom color reads the same way as the defaults, just a different hue.
 */
export function hexToRgba(hex: string, alpha: number): string {
  const match = /^#?([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(hex);
  if (!match) return hex;

  const [, r, g, b] = match;
  return `rgba(${parseInt(r!, 16)}, ${parseInt(g!, 16)}, ${parseInt(b!, 16)}, ${alpha})`;
}

/**
 * --app-accent-rgb exists because --app-fcal-today-bg/.wtf-fmonth-day-cell.is-today
 * need the bare "r, g, b" channels to build their own rgba(var(--app-accent-rgb), a)
 * — CSS can't pull channels back out of a hex custom property. Whenever
 * --app-accent is overridden with an owner's custom color, --app-accent-rgb
 * must be set alongside it or the today-highlight backgrounds silently keep
 * using the default accent's RGB instead of the custom one.
 */
export function hexToRgbTriplet(hex: string): string {
  const match = /^#?([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(hex);
  if (!match) return hex;

  const [, r, g, b] = match;
  return `${parseInt(r!, 16)}, ${parseInt(g!, 16)}, ${parseInt(b!, 16)}`;
}

/**
 * Matches dark-theme.css's own per-theme block alphas exactly (checked
 * against the reference site's own computed styles) — the light and dark
 * defaults use different alphas, not just different base colors, so an
 * owner's custom color needs the theme-appropriate alpha too or it reads
 * over/under-saturated next to Bootstrap's own theme-native controls.
 */
export const BLOCK_ALPHA = {
  dark: {
    free: 0.35, busy: 0.3, highlighted: 0.35, sleep: 0.35,
    // Matches `highlighted`'s alpha, not `busy`'s — a work block sits on
    // top of the same busy time it's tagging, so it needs to read as its
    // own distinct overlay rather than blend into the plain-busy wash
    // underneath.
    work: 0.35,
    // Same reasoning as work above — an overlay on top of busy/free time,
    // needs to read as its own distinct layer.
    school: 0.35,
    // Same reasoning as work/school above.
    public: 0.35,
  },
  light: {
    free: 0.25, busy: 0.2, highlighted: 0.3, sleep: 0.3,
    work: 0.3,
    school: 0.3,
    public: 0.3,
  },
} as const;

/**
 * Bootstrap's own pre-5.3 `color-yiq()` Sass function, ported to run at
 * runtime — dark-theme.css can't use the Sass version since --app-accent is
 * a CSS custom property set from an owner's palette choice, not a Sass
 * variable resolved at build time. Buttons painted with an owner's accent
 * color (.btn-primary, .btn-secondary, the active Month/Week toggle, etc.)
 * need their label color picked per-swatch instead of assuming the accent
 * is always dark enough for light text — ColorPalette.php's swatches span
 * both (charcoal to fog, red to amber), and "Today"/active-view buttons in
 * dark theme were rendering near-black text (--app-bg's dark-theme value)
 * on a medium-lightness accent, unreadable.
 */
export function yiqTextColor(hex: string): '#000' | '#fff' {
  const match = /^#?([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(hex);
  if (!match) return '#fff';

  const [, r, g, b] = match;
  const yiq = (parseInt(r!, 16) * 299 + parseInt(g!, 16) * 587 + parseInt(b!, 16) * 114) / 1000;

  return yiq >= 150 ? '#000' : '#fff';
}

/**
 * dark-theme.css's own --app-fcal-text-* block-label-text formulas,
 * mirrored here verbatim — CLAUDE.md's own documented gotcha: a custom
 * property whose value embeds var() resolves against the scope where IT
 * is declared, not wherever it's later inherited to. Those formulas are
 * declared once at :root, so they freeze against :root's OWN --app-hue-*
 * fallback the moment the browser first computes :root's style — any
 * element that overrides --app-hue-sleep/work/etc. on ITSELF (Free/Show.vue's
 * rootStyle, SettingsCalendarCard.vue's live preview) never actually
 * affects that already-frozen, inherited value, so the block's own
 * background correctly picks up a customized color while its label TEXT
 * silently keeps rendering in the hardcoded default hue instead.
 *
 * The fix is to redeclare the identical formula on that same element too
 * (spread this into the same style object that sets --app-hue-*), forcing
 * a fresh evaluation against the --app-hue-* values ALSO set right there.
 * SettingsPublicPageCard.vue's dual-theme preview doesn't strictly need
 * this (its .wtf-theme-preview CSS class already redeclares the same
 * formulas), but includes it anyway for the same reason every other
 * consumer must: relying on a specific wrapping class to remember this
 * is exactly the fragile setup that let the other two consumers regress
 * silently in the first place.
 */
export function fcalTextVars(): Record<string, string> {
  return {
    '--app-fcal-text-free': 'color-mix(in srgb, var(--app-hue-free, var(--app-accent)) 65%, var(--app-text) 35%)',
    '--app-fcal-text-highlighted': 'color-mix(in srgb, var(--app-hue-highlighted, #ffd60a) 65%, var(--app-text) 35%)',
    '--app-fcal-text-work': 'color-mix(in srgb, var(--app-hue-work, #8b5e34) 65%, var(--app-text) 35%)',
    '--app-fcal-text-school': 'color-mix(in srgb, var(--app-hue-school, #2f9e44) 65%, var(--app-text) 35%)',
    '--app-fcal-text-public': 'color-mix(in srgb, var(--app-hue-public, #343a40) 65%, var(--app-text) 35%)',
    '--app-fcal-text-sleep': 'color-mix(in srgb, var(--app-hue-sleep, #6f42c1) 65%, var(--app-text) 35%)',
  };
}
