/**
 * A fixed, curated set of color options — not a free-form hex picker.
 * Letting an owner type any arbitrary hex meant a color picked while
 * previewing one theme often read badly on the other (a light pastel
 * chosen in light mode nearly disappears against dark mode's own dark
 * background, and vice versa). Every swatch here instead pairs a
 * light-theme hex with a dark-theme hex hand-picked to stay readable
 * against that theme's own background.
 *
 * "Blue"/"Purple"/"Amber"/"Slate"/"Silver" anchor this app's own
 * pre-existing default hues (accent/sleep/highlighted/busy/secondary); the
 * rest fill out the hue wheel around them at roughly even spacing so the
 * full set reads as one consistent family, not a grab-bag.
 *
 * Mirrored in app/Support/ColorPalette.php (key list only, for server-side
 * validation) — keep both in sync.
 */
export interface ColorSwatch {
  key: string;
  label: string;
  light: string;
  dark: string;
}

export const COLOR_PALETTE: ColorSwatch[] = [
  { key: 'blue', label: 'Blue', light: '#6181b6', dark: '#6181b6' },
  { key: 'indigo', label: 'Indigo', light: '#5c6bc0', dark: '#7986cb' },
  { key: 'purple', label: 'Purple', light: '#6f42c1', dark: '#9775fa' },
  { key: 'pink', label: 'Pink', light: '#d6336c', dark: '#f06595' },
  { key: 'red', label: 'Red', light: '#e03131', dark: '#ff8787' },
  { key: 'amber', label: 'Amber', light: '#f08c00', dark: '#ffc107' },
  { key: 'green', label: 'Green', light: '#2f9e44', dark: '#69db7c' },
  { key: 'teal', label: 'Teal', light: '#0c8599', dark: '#66d9e8' },
  { key: 'slate', label: 'Slate', light: '#212529', dark: '#495057' },
  { key: 'silver', label: 'Silver', light: '#6c757d', dark: '#adb5bd' },
];

export type ColorSlot = 'accent' | 'secondary' | 'free' | 'busy' | 'sleep' | 'highlighted';

/** The pre-existing per-slot default, expressed as a palette key instead of a raw hex. */
export const DEFAULT_SWATCH_KEY: Record<ColorSlot, string> = {
  accent: 'blue',
  secondary: 'silver',
  free: 'blue',
  busy: 'slate',
  sleep: 'purple',
  highlighted: 'amber',
};

export function swatchByKey(key: string | null | undefined): ColorSwatch | undefined {
  return COLOR_PALETTE.find((swatch) => swatch.key === key);
}

/** Resolves a (possibly unset/invalid) stored key to an actual hex for the given slot and theme, falling back to that slot's default swatch. */
export function resolveSwatchHex(key: string | null | undefined, slot: ColorSlot, theme: 'light' | 'dark'): string {
  const swatch = swatchByKey(key) ?? swatchByKey(DEFAULT_SWATCH_KEY[slot])!;
  return theme === 'dark' ? swatch.dark : swatch.light;
}
