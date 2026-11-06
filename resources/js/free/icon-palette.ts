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
import type { IconDefinition, IconName } from '@fortawesome/fontawesome-svg-core';
import {
  faAnchor,
  faAngleDown,
  faAnglesDown,
  faAnglesUp,
  faAngleUp,
  faAppleWhole,
  faArrowDown,
  faArrowLeft,
  faArrowRight,
  faArrowTrendDown,
  faArrowTrendUp,
  faArrowUp,
  faBaby,
  faBan,
  faBasketball,
  faBatteryEmpty,
  faBatteryFull,
  faBatteryHalf,
  faBatteryQuarter,
  faBatteryThreeQuarters,
  faBed,
  faBell,
  faBicycle,
  faBolt,
  faBookAtlas,
  faBookBookmark,
  faBookOpen,
  faBrain,
  faBriefcase,
  faBriefcaseMedical,
  faBuilding,
  faBuildingColumns,
  faCakeCandles,
  faCalendarCheck,
  faCalendarXmark,
  faCamera,
  faCampground,
  faCar,
  faCaravan,
  faCat,
  faChartLine,
  faCheck,
  faChessKnight,
  faChild,
  faCircleCheck,
  faCircleDot,
  faCircleExclamation,
  faCircleHalfStroke,
  faCircleXmark,
  faCity,
  faClock,
  faCloud,
  faCloudMoon,
  faCloudRain,
  faCoffee,
  faCompass,
  faComputer,
  faCouch,
  faCrown,
  faDog,
  faDoorClosed,
  faDoorOpen,
  faDroplet,
  faDrum,
  faDumbbell,
  faEquals,
  faFaceFrown,
  faFaceGrinBeam,
  faFaceMeh,
  faFaceSmile,
  faFilm,
  faFire,
  faFish,
  faFlag,
  faFlagCheckered,
  faFlask,
  faFutbol,
  faGamepad,
  faGaugeSimple,
  faGaugeSimpleHigh,
  faGaugeSimpleMed,
  faGift,
  faGlobe,
  faGraduationCap,
  faGuitar,
  faHammer,
  faHandshake,
  faHeart,
  faHeartPulse,
  faHourglassEnd,
  faHourglassHalf,
  faHourglassStart,
  faHouse,
  faIceCream,
  faIndustry,
  faKey,
  faLaptop,
  faLeaf,
  faLightbulb,
  faLock,
  faMap,
  faMasksTheater,
  faMedal,
  faMicroscope,
  faMinus,
  faMoon,
  faMountain,
  faMugSaucer,
  faMusic,
  faPaintbrush,
  faPalette,
  faPaperPlane,
  faPaw,
  faPeopleGroup,
  faPersonHiking,
  faPersonRunning,
  faPersonSwimming,
  faPills,
  faPizzaSlice,
  faPlane,
  faPoop,
  faPuzzlePiece,
  faRankingStar,
  faRing,
  faRocket,
  faScaleBalanced,
  faSchool,
  faScroll,
  faSeedling,
  faShip,
  faSignal,
  faSnowflake,
  faSquareRootVariable,
  faStar,
  faStarHalf,
  faStethoscope,
  faSun,
  faTemperatureEmpty,
  faTemperatureFull,
  faTemperatureHalf,
  faTemperatureQuarter,
  faTemperatureThreeQuarters,
  faTent,
  faThumbsDown,
  faThumbsUp,
  faTicket,
  faToggleOff,
  faToggleOn,
  faTrailer,
  faTrain,
  faTree,
  faTrafficLight,
  faTrophy,
  faUmbrella,
  faUmbrellaBeach,
  faUserGraduate,
  faUsers,
  faUserTie,
  faUtensils,
  faVolleyball,
  faVolumeHigh,
  faVolumeLow,
  faVolumeOff,
  faVrCardboard,
  faWarehouse,
  faWrench,
  faXmark,
} from '@fortawesome/free-solid-svg-icons';

/**
 * Signal-strength glyphs. Font Awesome Free ships only the full-strength bars, so the graded ones are
 * drawn here as ordinary icon definitions (512x512 grid, four bar slots; a slot that isn't lit shrinks
 * to a small square so the bar count still reads at a glance).
 */
function signalBars(lit: number): IconDefinition {
  const slotWidth = 88;
  const stub = 88;
  const path = [0, 1, 2, 3]
    .map((slot) => {
      const height = slot < lit ? 120 * (slot + 1) : stub;
      const x = 32 + slot * 120;

      return `M${x} ${480 - height}h${slotWidth}v${height}h-${slotWidth}z`;
    })
    .join('');

  return { prefix: 'fas', iconName: `signal-${lit}` as IconName, icon: [512, 512, [], 'e000', path] };
}

export interface IconOption {
  key: string;
  label: string;
  /** A single, purely cosmetic category for grouping this catalog into a browsable list (IconPicker.vue) — never a restriction on which slot this icon can be picked for. See IconKey::group()'s own doc comment. */
  group: string;
  /** Extra search terms beyond label/key — see IconKey::keywords()'s own doc comment. */
  keywords: string[];
}

export type IconSlot = 'free' | 'busy' | 'work' | 'school' | 'public' | 'sleep' | 'highlighted';

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
  mountain: faMountain,
  leaf: faLeaf,
  seedling: faSeedling,
  compass: faCompass,
  'umbrella-beach': faUmbrellaBeach,
  'person-hiking': faPersonHiking,
  fire: faFire,
  cloud: faCloud,
  'cloud-rain': faCloudRain,
  snowflake: faSnowflake,
  bolt: faBolt,
  umbrella: faUmbrella,
  droplet: faDroplet,
  cat: faCat,
  dog: faDog,
  fish: faFish,
  'pizza-slice': faPizzaSlice,
  'mug-saucer': faMugSaucer,
  'ice-cream': faIceCream,
  'cake-candles': faCakeCandles,
  futbol: faFutbol,
  basketball: faBasketball,
  bicycle: faBicycle,
  'person-running': faPersonRunning,
  'person-swimming': faPersonSwimming,
  volleyball: faVolleyball,
  train: faTrain,
  ship: faShip,
  rocket: faRocket,
  'paper-plane': faPaperPlane,
  map: faMap,
  globe: faGlobe,
  anchor: faAnchor,
  'heart-pulse': faHeartPulse,
  pills: faPills,
  stethoscope: faStethoscope,
  'briefcase-medical': faBriefcaseMedical,
  wrench: faWrench,
  hammer: faHammer,
  key: faKey,
  couch: faCouch,
  'scale-balanced': faScaleBalanced,
  palette: faPalette,
  paintbrush: faPaintbrush,
  camera: faCamera,
  film: faFilm,
  guitar: faGuitar,
  drum: faDrum,
  'puzzle-piece': faPuzzlePiece,
  ticket: faTicket,
  'masks-theater': faMasksTheater,
  'chess-knight': faChessKnight,
  baby: faBaby,
  child: faChild,
  crown: faCrown,
  ring: faRing,
  lightbulb: faLightbulb,
  'vr-cardboard': faVrCardboard,
  'temperature-empty': faTemperatureEmpty,
  'temperature-quarter': faTemperatureQuarter,
  'temperature-half': faTemperatureHalf,
  'temperature-three-quarters': faTemperatureThreeQuarters,
  'temperature-full': faTemperatureFull,
  'gauge-low': faGaugeSimple,
  'gauge-medium': faGaugeSimpleMed,
  'gauge-high': faGaugeSimpleHigh,
  'hourglass-start': faHourglassStart,
  'hourglass-half': faHourglassHalf,
  'hourglass-end': faHourglassEnd,
  'volume-off': faVolumeOff,
  'volume-low': faVolumeLow,
  'volume-high': faVolumeHigh,
  'face-frown': faFaceFrown,
  'face-meh': faFaceMeh,
  'face-smile': faFaceSmile,
  'face-grin-beam': faFaceGrinBeam,
  'star-half': faStarHalf,
  'circle-dot': faCircleDot,
  'circle-half-stroke': faCircleHalfStroke,
  'medal': faMedal,
  'trophy': faTrophy,
  'ranking-star': faRankingStar,
  'thumbs-down': faThumbsDown,
  'equals': faEquals,
  'minus': faMinus,
  'arrow-up': faArrowUp,
  'arrow-down': faArrowDown,
  'arrow-left': faArrowLeft,
  'arrow-right': faArrowRight,
  'angle-up': faAngleUp,
  'angle-down': faAngleDown,
  'angles-up': faAnglesUp,
  'angles-down': faAnglesDown,
  'arrow-trend-up': faArrowTrendUp,
  'arrow-trend-down': faArrowTrendDown,
  'traffic-light': faTrafficLight,
  'flag-checkered': faFlagCheckered,
  'signal-0': signalBars(0),
  'signal-1': signalBars(1),
  'signal-2': signalBars(2),
  'signal-3': signalBars(3),
};

let icons: IconOption[] = [];
let defaultKeys: Record<IconSlot, string> = {
  free: '',
  busy: '',
  work: '',
  school: '',
  public: '',
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
