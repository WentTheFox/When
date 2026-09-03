/**
 * The client-side handle onto the app's fixed, curated icon palette — not
 * a free-form icon picker. app/Support/IconKey.php is the single source
 * of truth for which keys exist and their display labels; this module
 * just holds whatever it sent down as the `iconPalette` shared Inertia
 * prop (see HandleInertiaRequests::share()), seeded once at boot by
 * setIconPalette() (see app.ts). The server never accepts or sees an
 * actual icon reference for these slots — only a KEY (SettingsController
 * validates every *_icon_key with Rule::enum(IconKey::class)) — the
 * KEY -> real FontAwesome IconDefinition mapping below is the ONE place
 * that
 * translates our own stable names to whatever this app's installed FA
 * version currently calls them. If a future FA upgrade renames one of
 * these imports, this is the only line that needs to change — every
 * *_icon_key ever stored in the database keeps working unmodified.
 */
import type { IconDefinition } from '@fortawesome/fontawesome-svg-core';
import {
  faBan,
  faBed,
  faBell,
  faBookOpen,
  faBriefcase,
  faBuilding,
  faCalendarCheck,
  faCalendarXmark,
  faCar,
  faChartLine,
  faCheck,
  faCircleExclamation,
  faClock,
  faCloudMoon,
  faCoffee,
  faDumbbell,
  faFlag,
  faGamepad,
  faGift,
  faHeart,
  faHouse,
  faLaptop,
  faLock,
  faMoon,
  faMusic,
  faPaw,
  faPlane,
  faStar,
  faSun,
  faThumbsUp,
  faUsers,
  faUtensils,
  faXmark,
} from '@fortawesome/free-solid-svg-icons';

export interface IconOption {
  key: string;
  label: string;
}

export type IconSlot = 'free' | 'busy' | 'work' | 'sleep' | 'highlighted';

/** This app's own key -> the actual FA icon it currently renders as. Keep in sync with App\Support\IconKey's cases (same key set, one entry each). */
const ICON_KEY_TO_FA: Record<string, IconDefinition> = {
  moon: faMoon,
  bed: faBed,
  'cloud-moon': faCloudMoon,
  briefcase: faBriefcase,
  laptop: faLaptop,
  building: faBuilding,
  'chart-line': faChartLine,
  check: faCheck,
  'calendar-check': faCalendarCheck,
  sun: faSun,
  coffee: faCoffee,
  star: faStar,
  heart: faHeart,
  users: faUsers,
  gift: faGift,
  bell: faBell,
  ban: faBan,
  lock: faLock,
  x: faXmark,
  alert: faCircleExclamation,
  'calendar-x': faCalendarXmark,
  clock: faClock,
  house: faHouse,
  plane: faPlane,
  dumbbell: faDumbbell,
  book: faBookOpen,
  utensils: faUtensils,
  gamepad: faGamepad,
  music: faMusic,
  car: faCar,
  paw: faPaw,
  'thumbs-up': faThumbsUp,
  flag: faFlag,
};

let icons: IconOption[] = [];
let defaultKeys: Record<IconSlot, string> = {
  free: '',
  busy: '',
  work: '',
  sleep: '',
  highlighted: '',
};

/** Called once at boot (app.ts) with the `iconPalette` shared prop from the initial page load. */
export function setIconPalette(list: IconOption[], defaults: Record<IconSlot, string>): void {
  icons = list;
  defaultKeys = defaults;
}

export function getIconPalette(): IconOption[] {
  return icons;
}

export function getDefaultIconKey(slot: IconSlot): string {
  return defaultKeys[slot];
}

/** Resolves a (possibly unset/invalid) stored key to an actual FA icon for the given slot, falling back to that slot's default. */
export function resolveIcon(key: string | null | undefined, slot: IconSlot): IconDefinition {
  const resolvedKey = (key && ICON_KEY_TO_FA[key]) ? key : defaultKeys[slot];

  return ICON_KEY_TO_FA[resolvedKey] ?? faCheck;
}

export function faIconFor(key: string): IconDefinition | undefined {
  return ICON_KEY_TO_FA[key];
}
