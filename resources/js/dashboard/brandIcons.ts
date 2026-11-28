/**
 * Brand marks usable as rule/source icons, keyed `brand:<name>` like `flag:xx` country flags. Paths come from
 * Simple Icons (CC0); only the three named imports are bundled. The key lives only inside an encrypted blob
 * (an icon rule, a source's or category's icon), so the server never validates or sees it.
 */
import { siDiscord, siTelegram, siVrchat } from 'simple-icons';
import type { IconOption } from '../free/icon-palette';

export const BRAND_ICON_PREFIX = 'brand:';
export const BRANDS_GROUP = 'Brands';

const BRANDS: Record<string, { label: string; path: string }> = {
  discord: { label: 'Discord', path: siDiscord.path },
  telegram: { label: 'Telegram', path: siTelegram.path },
  vrchat: { label: 'VRChat', path: siVrchat.path },
};

/** The picker entries for every brand, searchable by name. */
export const brandIconOptions: IconOption[] = Object.entries(BRANDS).map(([name, { label }]) => ({
  key: BRAND_ICON_PREFIX + name,
  label,
  group: BRANDS_GROUP,
  keywords: [name, 'brand', 'logo', 'social'],
}));

/** The SVG path (24x24 viewBox) behind a `brand:<name>` key, or null when it is not a known brand. */
export function brandIconPath(key: string | null | undefined): string | null {
  if (!key || !key.startsWith(BRAND_ICON_PREFIX)) return null;

  return BRANDS[key.slice(BRAND_ICON_PREFIX.length)]?.path ?? null;
}
