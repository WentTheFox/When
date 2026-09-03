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
  faAppleWhole,
  faBan,
  faBatteryEmpty,
  faBatteryFull,
  faBatteryHalf,
  faBatteryQuarter,
  faBatteryThreeQuarters,
  faBed,
  faBell,
  faBookAtlas,
  faBookBookmark,
  faBookOpen,
  faBrain,
  faBriefcase,
  faBuilding,
  faBuildingColumns,
  faCalendarCheck,
  faCalendarXmark,
  faCampground,
  faCar,
  faCaravan,
  faChartLine,
  faCheck,
  faCircleCheck,
  faCircleExclamation,
  faCircleXmark,
  faCity,
  faClock,
  faCloudMoon,
  faCoffee,
  faComputer,
  faDoorClosed,
  faDoorOpen,
  faDumbbell,
  faFlag,
  faFlask,
  faGamepad,
  faGift,
  faHandshake,
  faHeart,
  faHouse,
  faIndustry,
  faLaptop,
  faLock,
  faMicroscope,
  faMoon,
  faMusic,
  faPaw,
  faPeopleGroup,
  faPlane,
  faPoop,
  faSchool,
  faScroll,
  faSignal,
  faSquareRootVariable,
  faStar,
  faSun,
  faTent,
  faThumbsUp,
  faToggleOff,
  faToggleOn,
  faTrailer,
  faTree,
  faUserTie,
  faUsers,
  faUtensils,
  faWarehouse,
  faXmark, faUserGraduate, faGraduationCap,
} from '@fortawesome/free-solid-svg-icons';

export interface IconOption {
  key: string;
  label: string;
  /** Which of the six block-type slots this icon is a sensible fit for — see IconKey::categories()'s own doc comment. */
  categories: IconSlot[];
}

export type IconSlot = 'free' | 'busy' | 'work' | 'school' | 'sleep' | 'highlighted';

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
  city: faCity,
  industry: faIndustry,
  warehouse: faWarehouse,
  'building-columns': faBuildingColumns,
  'user-tie': faUserTie,
  handshake: faHandshake,
  'people-group': faPeopleGroup,
  'door-open': faDoorOpen,
  'door-closed': faDoorClosed,
  'toggle-on': faToggleOn,
  'toggle-off': faToggleOff,
  signal: faSignal,
  'circle-check': faCircleCheck,
  'circle-xmark': faCircleXmark,
  poop: faPoop,
  'battery-full': faBatteryFull,
  'battery-three-quarters': faBatteryThreeQuarters,
  'battery-half': faBatteryHalf,
  'battery-quarter': faBatteryQuarter,
  'battery-empty': faBatteryEmpty,
  caravan: faCaravan,
  trailer: faTrailer,
  tent: faTent,
  campground: faCampground,
  tree: faTree,
  books: faBookBookmark,
  apple: faAppleWhole,
  school: faSchool,
  brain: faBrain,
  math: faSquareRootVariable,
  chemistry: faFlask,
  science: faMicroscope,
  history: faScroll,
  // The other faBook* variants (bible/dead/journal-whills/medical/quran/
  // reader/skull/tanakh) don't read as any of this app's own school
  // subjects the way an atlas reads as geography — kept as their more
  // subject-specific icons (flask/microscope/√x/scroll) instead of
  // forcing a book-shaped icon onto every subject just for consistency.
  geography: faBookAtlas,
  computer: faComputer,
  graduate: faUserGraduate,
  'graduation-cap': faGraduationCap,
};

let icons: IconOption[] = [];
let defaultKeys: Record<IconSlot, string> = {
  free: '',
  busy: '',
  work: '',
  school: '',
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

/** The subset of the full palette that's actually a sensible fit for one slot — see IconOption.categories/IconKey::categories()'s own doc comment. Settings.vue's picker uses this instead of the full list so e.g. "dumbbell" never shows up as an option for "sleep". */
export function iconsForSlot(slot: IconSlot): IconOption[] {
  return icons.filter((icon) => icon.categories.includes(slot));
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
