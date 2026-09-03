/**
 * The client-side handle onto the app's curated now_color_key quick-picks
 * — app/Support/NowColorPresetKey.php is the single source of truth for
 * the keys/labels/light+dark hex values; this module just holds whatever
 * it sent down as the `nowColorPresets` shared Inertia prop (see
 * HandleInertiaRequests::share()), seeded once at boot by
 * setNowColorPresets() (see app.ts). Same KEY-only scheme as
 * color-palette.ts now — the server never accepts a raw hex back for
 * now_color_key either (SettingsController validates it with
 * Rule::enum(NowColorPresetKey::class)).
 */
export interface NowColorPreset {
  key: string;
  label: string;
  light: string;
  dark: string;
}

let presets: NowColorPreset[] = [];
let defaultKey = '';

/** Called once at boot (app.ts) with the `nowColorPresets` shared prop from the initial page load. */
export function setNowColorPresets(list: NowColorPreset[], defaultPresetKey: string): void {
  presets = list;
  defaultKey = defaultPresetKey;
}

export function getNowColorPresets(): NowColorPreset[] {
  return presets;
}

export function getDefaultNowColorKey(): string {
  return defaultKey;
}

export function nowPresetByKey(key: string | null | undefined): NowColorPreset | undefined {
  return presets.find((preset) => preset.key === key);
}

/** Resolves a (possibly unset/invalid) stored key to an actual hex for the given theme, falling back to the default preset. */
export function resolveNowColorHex(key: string | null | undefined, theme: 'light' | 'dark'): string {
  const preset = nowPresetByKey(key) ?? nowPresetByKey(defaultKey);
  // Only reachable if setNowColorPresets() was never called (or the server
  // sent an empty list) — every real request shares a non-empty one.
  if (!preset) return '#ff0000';
  return theme === 'dark' ? preset.dark : preset.light;
}
