<?php

namespace App\Support;

/**
 * The app's fixed, curated icon options for the six calendar block types
 * (free/busy/work/school/sleep/highlighted) — not a free-form icon picker, same
 * "curated list, not arbitrary input" spirit as ColorSwatchKey. Every key
 * here is this app's own name, deliberately never a raw Font Awesome icon
 * identifier: FA has renamed icons across major versions before (the
 * "coffee mug" icon alone has gone by faCoffee and faMugHot across
 * FA5/FA6), and a value stored in `users.*_icon_key` outlives any one FA
 * version this app happens to be built against. The actual key -> FA-icon
 * mapping lives entirely client-side, in resources/js/free/icon-
 * palette.ts's ICON_KEY_TO_FA — this enum only hands the frontend the KEY
 * + a display label() (+ which slot(s) it's a sensible fit for, see
 * categories() below) for the picker UI, never an icon reference of its
 * own, so a future FA icon rename is a one-line edit to that single TS
 * map, never a migration or a stored-value change.
 *
 * Unlike ColorSwatchKey, there's no light/dark pair per case — a glyph's
 * shape doesn't need a theme-specific variant the way a hex color does;
 * it inherits the same --wtf-fcal-text-* color CSS already gives the
 * block's label text.
 *
 * A backed enum rather than an assoc array plus a parallel KEYS list —
 * self::cases() is already the ordered key list, and each case's own
 * label()/categories() lives right next to it instead of in a lookup
 * table that could silently drift out of sync with a separate key array.
 */
enum IconKey: string
{
    case Moon = 'moon';
    case Bed = 'bed';
    case CloudMoon = 'cloud-moon';
    case Briefcase = 'briefcase';
    case Laptop = 'laptop';
    case Building = 'building';
    case ChartLine = 'chart-line';
    case Check = 'check';
    case CalendarCheck = 'calendar-check';
    case Sun = 'sun';
    case Coffee = 'coffee';
    case Star = 'star';
    case Heart = 'heart';
    case Users = 'users';
    case Gift = 'gift';
    case Bell = 'bell';
    case Ban = 'ban';
    case Lock = 'lock';
    case X = 'x';
    case Alert = 'alert';
    case CalendarX = 'calendar-x';
    case Clock = 'clock';
    case House = 'house';
    case Plane = 'plane';
    case Dumbbell = 'dumbbell';
    case Book = 'book';
    case Utensils = 'utensils';
    case Gamepad = 'gamepad';
    case Music = 'music';
    case Car = 'car';
    case Paw = 'paw';
    case ThumbsUp = 'thumbs-up';
    case Flag = 'flag';

    // Buildings/people (work) — added alongside the free/busy signal set
    // below, all four groups picked to give each slot's picker enough
    // genuinely-fitting options that a category filter (see categories()
    // below) doesn't leave any slot looking sparse.
    case City = 'city';
    case Industry = 'industry';
    case Warehouse = 'warehouse';
    case BuildingColumns = 'building-columns';
    case UserTie = 'user-tie';
    case Handshake = 'handshake';
    case PeopleGroup = 'people-group';

    // Free/busy signs and signals.
    case DoorOpen = 'door-open';
    case DoorClosed = 'door-closed';
    case ToggleOn = 'toggle-on';
    case ToggleOff = 'toggle-off';
    case Signal = 'signal';
    case CircleCheck = 'circle-check';
    case CircleXmark = 'circle-xmark';

    // Not a serious option — deliberately only ever offered for "work".
    case Poop = 'poop';

    // Battery levels double as a free/busy metaphor — "full" reads as
    // available capacity, "empty" as none left.
    case BatteryFull = 'battery-full';
    case BatteryThreeQuarters = 'battery-three-quarters';
    case BatteryHalf = 'battery-half';
    case BatteryQuarter = 'battery-quarter';
    case BatteryEmpty = 'battery-empty';

    // Getting away from it all — a camping/travel-lodging theme that fits
    // both "sleep" (it's where you're actually sleeping) and "free" (a
    // trip is free time) equally well.
    case Caravan = 'caravan';
    case Trailer = 'trailer';
    case Tent = 'tent';
    case Campground = 'campground';
    case Tree = 'tree';

    // "School" category icons — dedicated to that one slot, not shared
    // with anything else the way most icons above deliberately are.
    case Books = 'books';
    case Apple = 'apple';
    case School = 'school';
    case Brain = 'brain';
    case Math = 'math';
    case Chemistry = 'chemistry';
    case Science = 'science';
    case History = 'history';
    case Geography = 'geography';
    case Computer = 'computer';

    public function label(): string
    {
        return match ($this) {
            self::Moon => 'Moon',
            self::Bed => 'Bed',
            self::CloudMoon => 'Cloud moon',
            self::Briefcase => 'Briefcase',
            self::Laptop => 'Laptop',
            self::Building => 'Building',
            self::ChartLine => 'Chart',
            self::Check => 'Check',
            self::CalendarCheck => 'Calendar check',
            self::Sun => 'Sun',
            self::Coffee => 'Coffee',
            self::Star => 'Star',
            self::Heart => 'Heart',
            self::Users => 'Users',
            self::Gift => 'Gift',
            self::Bell => 'Bell',
            self::Ban => 'Ban',
            self::Lock => 'Lock',
            self::X => 'X',
            self::Alert => 'Alert',
            self::CalendarX => 'Calendar X',
            self::Clock => 'Clock',
            self::House => 'House',
            self::Plane => 'Plane',
            self::Dumbbell => 'Dumbbell',
            self::Book => 'Book',
            self::Utensils => 'Utensils',
            self::Gamepad => 'Gamepad',
            self::Music => 'Music',
            self::Car => 'Car',
            self::Paw => 'Paw',
            self::ThumbsUp => 'Thumbs up',
            self::Flag => 'Flag',
            self::City => 'City',
            self::Industry => 'Factory',
            self::Warehouse => 'Warehouse',
            self::BuildingColumns => 'Office building',
            self::UserTie => 'Businessperson',
            self::Handshake => 'Handshake',
            self::PeopleGroup => 'People group',
            self::DoorOpen => 'Open door',
            self::DoorClosed => 'Closed door',
            self::ToggleOn => 'Toggle on',
            self::ToggleOff => 'Toggle off',
            self::Signal => 'Signal',
            self::CircleCheck => 'Circle check',
            self::CircleXmark => 'Circle X',
            self::Poop => 'Poop',
            self::BatteryFull => 'Battery full',
            self::BatteryThreeQuarters => 'Battery three quarters',
            self::BatteryHalf => 'Battery half',
            self::BatteryQuarter => 'Battery quarter',
            self::BatteryEmpty => 'Battery empty',
            self::Caravan => 'Caravan',
            self::Trailer => 'Trailer',
            self::Tent => 'Tent',
            self::Campground => 'Campground',
            self::Tree => 'Tree',
            self::Books => 'Books',
            self::Apple => 'Apple',
            self::School => 'School',
            self::Brain => 'Brain',
            self::Math => 'Math',
            self::Chemistry => 'Chemistry',
            self::Science => 'Science',
            self::History => 'History',
            self::Geography => 'Geography',
            self::Computer => 'Computer',
        };
    }

    /**
     * Which of the six calendar-block slots (see IconPalette::DEFAULT_
     * KEYS/IconSlot in icon-palette.ts) this icon is actually a sensible
     * fit for — Settings.vue's picker filters each slot's swatch grid
     * down to just these, so e.g. a "dumbbell" never shows up as an
     * option for "sleep". Purely a UI-filtering nicety: SettingsController
     * still validates every *_icon_key with a plain Rule::enum(self::
     * class), not scoped by slot, so this categorization can be
     * loosened, tightened, or entirely re-tagged later without a
     * migration — nothing is stored beyond the bare key either way.
     *
     * Many icons deliberately list more than one slot (e.g. "star" fits
     * both "free" and "highlighted") rather than being forced into a
     * single bucket — the categories only need to rule out the genuinely
     * nonsensical combinations, not pick One True Slot per icon.
     *
     * @return list<string>
     */
    public function categories(): array
    {
        return match ($this) {
            self::Moon, self::Bed, self::CloudMoon => ['sleep'],
            self::Briefcase, self::Laptop, self::Building, self::ChartLine => ['work'],
            self::Check => ['free', 'highlighted'],
            self::CalendarCheck => ['free'],
            self::Sun => ['free'],
            self::Coffee => ['work', 'free'],
            self::Star => ['highlighted', 'free'],
            self::Heart => ['highlighted'],
            self::Users => ['work', 'highlighted'],
            self::Gift => ['highlighted'],
            self::Bell => ['busy', 'highlighted'],
            self::Ban, self::Lock, self::X, self::Alert, self::CalendarX => ['busy'],
            self::Clock => ['busy', 'work'],
            self::House => ['free', 'sleep'],
            self::Plane, self::Dumbbell, self::Book, self::Utensils,
            self::Gamepad, self::Music, self::Car, self::Paw => ['free'],
            self::ThumbsUp => ['highlighted', 'free'],
            self::Flag => ['highlighted'],
            self::City, self::Industry, self::Warehouse, self::BuildingColumns, self::UserTie => ['work'],
            self::Handshake, self::PeopleGroup => ['work', 'highlighted'],
            self::DoorOpen, self::ToggleOn => ['free'],
            self::DoorClosed, self::ToggleOff, self::CircleXmark => ['busy'],
            self::Signal => ['free', 'busy'],
            self::CircleCheck => ['free', 'highlighted'],
            self::Poop => ['work'],
            self::BatteryFull, self::BatteryThreeQuarters => ['free'],
            self::BatteryHalf => ['free', 'busy'],
            self::BatteryQuarter, self::BatteryEmpty => ['busy'],
            self::Caravan, self::Trailer, self::Tent, self::Campground, self::Tree => ['sleep', 'free'],
            self::Books, self::Apple, self::School, self::Brain, self::Math,
            self::Chemistry, self::Science, self::History, self::Geography,
            self::Computer => ['school'],
        };
    }

    /**
     * Shape consumed by resources/js/free/icon-palette.ts's
     * setIconPalette() — an ordered {key, label, categories}[], sorted
     * alphabetically by label() rather than the enum's own declaration
     * order (which is grouped by when each icon was added, not anything
     * an owner scanning the picker would find meaningful).
     */
    public static function forFrontend(): array
    {
        $cases = self::cases();

        usort($cases, fn (self $a, self $b) => strcasecmp($a->label(), $b->label()));

        return array_map(
            fn (self $case) => ['key' => $case->value, 'label' => $case->label(), 'categories' => $case->categories()],
            $cases,
        );
    }
}
