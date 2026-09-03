/**
 * The client-side handle onto the app's fixed, curated color palette — not
 * a free-form hex picker, and not a hardcoded copy of the hex values
 * either. app/Support/ColorSwatchKey.php is the single source of truth;
 * this module just holds whatever it sent down as the `colorPalette`
 * shared Inertia prop (see HandleInertiaRequests::share()), seeded once at
 * boot by setColorPalette() (see app.ts). The server never accepts a hex
 * back from the client for these slots — only a KEY (SettingsController
 * validates every *_color_key with Rule::enum(ColorSwatchKey::class)) —
 * so there's no path by which the client could ever need to invent or
 * mutate a swatch's hex.
 */
export interface ColorSwatch {
  key: string;
  label: string;
  light: string;
  dark: string;
}

export type ColorSlot = 'accent' | 'secondary' | 'free' | 'busy' | 'work' | 'sleep' | 'highlighted';

let palette: ColorSwatch[] = [];
let defaultKeys: Record<ColorSlot, string> = {
  accent: '',
  secondary: '',
  free: '',
  busy: '',
  work: '',
  sleep: '',
  highlighted: '',
};

/** Called once at boot (app.ts) with the `colorPalette` shared prop from the initial page load. */
export function setColorPalette(swatches: ColorSwatch[], defaults: Record<ColorSlot, string>): void {
  palette = swatches;
  defaultKeys = defaults;
}

export function getColorPalette(): ColorSwatch[] {
  return palette;
}

export function getDefaultSwatchKey(slot: ColorSlot): string {
  return defaultKeys[slot];
}

export function swatchByKey(key: string | null | undefined): ColorSwatch | undefined {
  return palette.find((swatch) => swatch.key === key);
}

/** Resolves a (possibly unset/invalid) stored key to an actual hex for the given slot and theme, falling back to that slot's default swatch. */
export function resolveSwatchHex(key: string | null | undefined, slot: ColorSlot, theme: 'light' | 'dark'): string {
  const swatch = swatchByKey(key) ?? swatchByKey(defaultKeys[slot]);
  // Only reachable if setColorPalette() was never called (or the server
  // sent an empty palette) — every real request shares a non-empty one.
  if (!swatch) return theme === 'dark' ? '#6181b6' : '#6181b6';
  return theme === 'dark' ? swatch.dark : swatch.light;
}
