/**
 * <input type="color"> (Settings.vue's accent/free/busy/sleep/highlighted
 * pickers) only ever produces a solid "#RRGGBB" hex — no alpha channel.
 * Binding that straight to --wtf-color-free/busy/highlighted/sleep would
 * make an owner's customized blocks fully opaque, losing the transparent-
 * wash treatment every default color gets (see dark-theme.css's --wtf-
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
 * --wtf-accent-rgb exists because --wtf-fcal-today-bg/.wtf-fmonth-day-cell.is-today
 * need the bare "r, g, b" channels to build their own rgba(var(--wtf-accent-rgb), a)
 * — CSS can't pull channels back out of a hex custom property. Whenever
 * --wtf-accent is overridden with an owner's custom color, --wtf-accent-rgb
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
  dark: { free: 0.35, busy: 0.3, highlighted: 0.35, sleep: 0.35 },
  light: { free: 0.25, busy: 0.2, highlighted: 0.3, sleep: 0.3 },
} as const;
