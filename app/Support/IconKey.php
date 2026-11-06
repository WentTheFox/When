<?php

namespace App\Support;

/**
 * The app's fixed, curated icon catalog — not a free-form icon picker,
 * same "curated list, not arbitrary input" spirit as ColorSwatchKey. Every
 * key here is this app's own name, deliberately never a raw Font Awesome
 * icon identifier: FA has renamed icons across major versions before (the
 * "coffee mug" icon alone has gone by faCoffee and faMugHot across
 * FA5/FA6), and a value stored in `users.*_icon_key` outlives any one FA
 * version this app happens to be built against. The actual key -> FA-icon
 * mapping lives entirely client-side, in resources/js/free/icon-
 * palette.ts's ICON_KEY_TO_FA — this enum only hands the frontend the KEY
 * + a display label() + a browsable group() + optional search keywords()
 * for the picker UI (resources/js/dashboard/IconPicker.vue), never an icon
 * reference of its own, so a future FA icon rename is a one-line edit to
 * that single TS map, never a migration or a stored-value change.
 *
 * Unlike ColorSwatchKey, there's no light/dark pair per case — a glyph's
 * shape doesn't need a theme-specific variant the way a hex color does;
 * it inherits the same --app-fcal-text-* color CSS already gives the
 * block's label text.
 *
 * A backed enum rather than an assoc array plus a parallel KEYS list —
 * self::cases() is already the ordered key list, and each case's own
 * label()/group()/keywords() lives right next to it instead of in a
 * lookup table that could silently drift out of sync with a separate key
 * array.
 *
 * group() replaces an earlier categories(): list<string> design that
 * tagged each icon with which *_icon_key SLOT (free/busy/work/school/
 * sleep/highlighted/public) it was a sensible fit for, and used that to
 * restrict each slot's own picker down to a filtered subset. IconPicker.vue
 * now shows the WHOLE catalog for every slot instead (with search, there's
 * no need to pre-filter to keep a picker browsable), so group() is purely
 * organizational — one single, cosmetic category per icon (e.g. "Sports &
 * fitness"), never a restriction on which slot it can be picked for.
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

    // Buildings/people (work).
    case City = 'city';
    case Industry = 'industry';
    case Warehouse = 'warehouse';
    case BuildingColumns = 'building-columns';
    case UserTie = 'user-tie';
    case Handshake = 'handshake';
    case PeopleGroup = 'people-group';

    // Status signs and signals.
    case DoorOpen = 'door-open';
    case DoorClosed = 'door-closed';
    case ToggleOn = 'toggle-on';
    case ToggleOff = 'toggle-off';
    case Signal = 'signal';
    case CircleCheck = 'circle-check';
    case CircleXmark = 'circle-xmark';

    // Not a serious option — kept out of every group but Misc.
    case Poop = 'poop';

    // Battery levels double as a free/busy metaphor — "full" reads as
    // available capacity, "empty" as none left.
    case BatteryFull = 'battery-full';
    case BatteryThreeQuarters = 'battery-three-quarters';
    case BatteryHalf = 'battery-half';
    case BatteryQuarter = 'battery-quarter';
    case BatteryEmpty = 'battery-empty';

    // Getting away from it all.
    case Caravan = 'caravan';
    case Trailer = 'trailer';
    case Tent = 'tent';
    case Campground = 'campground';
    case Tree = 'tree';

    // School/education.
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
    case Graduate = 'graduate';
    case GraduationCap = 'graduation-cap';

    // Nature & outdoors.
    case Mountain = 'mountain';
    case Leaf = 'leaf';
    case Seedling = 'seedling';
    case Compass = 'compass';
    case UmbrellaBeach = 'umbrella-beach';
    case PersonHiking = 'person-hiking';
    case Fire = 'fire';

    // Weather.
    case Cloud = 'cloud';
    case CloudRain = 'cloud-rain';
    case Snowflake = 'snowflake';
    case Bolt = 'bolt';
    case Umbrella = 'umbrella';
    case Droplet = 'droplet';

    // Animals.
    case Cat = 'cat';
    case Dog = 'dog';
    case Fish = 'fish';

    // Food & drink.
    case PizzaSlice = 'pizza-slice';
    case MugSaucer = 'mug-saucer';
    case IceCream = 'ice-cream';
    case CakeCandles = 'cake-candles';

    // Sports & fitness.
    case Futbol = 'futbol';
    case Basketball = 'basketball';
    case Bicycle = 'bicycle';
    case PersonRunning = 'person-running';
    case PersonSwimming = 'person-swimming';
    case Volleyball = 'volleyball';

    // Travel & transport.
    case Train = 'train';
    case Ship = 'ship';
    case Rocket = 'rocket';
    case PaperPlane = 'paper-plane';
    case Map = 'map';
    case Globe = 'globe';
    case Anchor = 'anchor';

    // Health.
    case HeartPulse = 'heart-pulse';
    case Pills = 'pills';
    case Stethoscope = 'stethoscope';
    case BriefcaseMedical = 'briefcase-medical';

    // Home & tools.
    case Wrench = 'wrench';
    case Hammer = 'hammer';
    case Key = 'key';
    case Couch = 'couch';
    case ScaleBalanced = 'scale-balanced';

    // Arts & hobbies.
    case Palette = 'palette';
    case Paintbrush = 'paintbrush';
    case Camera = 'camera';
    case Film = 'film';
    case Guitar = 'guitar';
    case Drum = 'drum';
    case PuzzlePiece = 'puzzle-piece';
    case Ticket = 'ticket';
    case MasksTheater = 'masks-theater';
    case ChessKnight = 'chess-knight';
    case VR = 'vr-cardboard';

    // People & social.
    case Baby = 'baby';
    case Child = 'child';
    case Crown = 'crown';
    case Ring = 'ring';

    // Misc.
    case Lightbulb = 'lightbulb';

    // Graded levels (signal bars, thermometer, gauge, hourglass, volume, mood), rankings and
    // arrows. Meant for icon rules on choice/number fields, so a rule can show "how much".
    case Signal0 = 'signal-0';
    case Signal1 = 'signal-1';
    case Signal2 = 'signal-2';
    case Signal3 = 'signal-3';
    case TemperatureEmpty = 'temperature-empty';
    case TemperatureQuarter = 'temperature-quarter';
    case TemperatureHalf = 'temperature-half';
    case TemperatureThreeQuarters = 'temperature-three-quarters';
    case TemperatureFull = 'temperature-full';
    case GaugeLow = 'gauge-low';
    case GaugeMedium = 'gauge-medium';
    case GaugeHigh = 'gauge-high';
    case HourglassStart = 'hourglass-start';
    case HourglassHalf = 'hourglass-half';
    case HourglassEnd = 'hourglass-end';
    case VolumeOff = 'volume-off';
    case VolumeLow = 'volume-low';
    case VolumeHigh = 'volume-high';
    case FaceFrown = 'face-frown';
    case FaceMeh = 'face-meh';
    case FaceSmile = 'face-smile';
    case FaceGrinBeam = 'face-grin-beam';
    case StarHalf = 'star-half';
    case CircleDot = 'circle-dot';
    case CircleHalfStroke = 'circle-half-stroke';
    case Medal = 'medal';
    case Trophy = 'trophy';
    case RankingStar = 'ranking-star';
    case ThumbsDown = 'thumbs-down';
    case Equals = 'equals';
    case Minus = 'minus';
    case ArrowUp = 'arrow-up';
    case ArrowDown = 'arrow-down';
    case ArrowLeft = 'arrow-left';
    case ArrowRight = 'arrow-right';
    case AngleUp = 'angle-up';
    case AngleDown = 'angle-down';
    case AnglesUp = 'angles-up';
    case AnglesDown = 'angles-down';
    case ArrowTrendUp = 'arrow-trend-up';
    case ArrowTrendDown = 'arrow-trend-down';
    case TrafficLight = 'traffic-light';
    case FlagCheckered = 'flag-checkered';

    public function label(): string
    {
        return match ($this) {
            self::CloudMoon => 'Cloud moon',
            self::ChartLine => 'Chart',
            self::CalendarCheck => 'Calendar check',
            self::CalendarX => 'Calendar X',
            self::ThumbsUp => 'Thumbs up',
            self::Industry => 'Factory',
            self::BuildingColumns => 'Office building',
            self::UserTie => 'Businessperson',
            self::PeopleGroup => 'People group',
            self::DoorOpen => 'Open door',
            self::DoorClosed => 'Closed door',
            self::ToggleOn => 'Toggle on',
            self::ToggleOff => 'Toggle off',
            self::CircleCheck => 'Circle check',
            self::CircleXmark => 'Circle X',
            self::BatteryFull => 'Battery full',
            self::BatteryThreeQuarters => 'Battery three quarters',
            self::BatteryHalf => 'Battery half',
            self::BatteryQuarter => 'Battery quarter',
            self::BatteryEmpty => 'Battery empty',
            self::GraduationCap => 'Graduation cap',
            self::UmbrellaBeach => 'Beach umbrella',
            self::PersonHiking => 'Hiking',
            self::CloudRain => 'Rain',
            self::PizzaSlice => 'Pizza',
            self::MugSaucer => 'Mug',
            self::IceCream => 'Ice cream',
            self::CakeCandles => 'Birthday cake',
            self::PersonRunning => 'Running',
            self::PersonSwimming => 'Swimming',
            self::PaperPlane => 'Paper plane',
            self::HeartPulse => 'Heart pulse',
            self::BriefcaseMedical => 'Medical briefcase',
            self::ScaleBalanced => 'Scales',
            self::PuzzlePiece => 'Puzzle piece',
            self::MasksTheater => 'Theater masks',
            self::ChessKnight => 'Chess',
            self::TrafficLight => 'Traffic light',
            self::FlagCheckered => 'Checkered flag',
            self::Signal0 => 'Signal none',
            self::Signal1 => 'Signal 1 bar',
            self::Signal2 => 'Signal 2 bars',
            self::Signal3 => 'Signal 3 bars',
            self::TemperatureEmpty => 'Thermometer empty',
            self::TemperatureQuarter => 'Thermometer quarter',
            self::TemperatureHalf => 'Thermometer half',
            self::TemperatureThreeQuarters => 'Thermometer three quarters',
            self::TemperatureFull => 'Thermometer full',
            self::GaugeLow => 'Gauge low',
            self::GaugeMedium => 'Gauge medium',
            self::GaugeHigh => 'Gauge high',
            self::HourglassStart => 'Hourglass start',
            self::HourglassHalf => 'Hourglass half',
            self::HourglassEnd => 'Hourglass end',
            self::VolumeOff => 'Volume off',
            self::VolumeLow => 'Volume low',
            self::VolumeHigh => 'Volume high',
            self::FaceFrown => 'Face frown',
            self::FaceMeh => 'Face meh',
            self::FaceSmile => 'Face smile',
            self::FaceGrinBeam => 'Face grin',
            self::StarHalf => 'Star half',
            self::CircleDot => 'Circle dot',
            self::CircleHalfStroke => 'Circle half',
            self::Medal => 'Medal',
            self::Trophy => 'Trophy',
            self::RankingStar => 'Ranking',
            self::ThumbsDown => 'Thumbs down',
            self::Equals => 'Equals',
            self::Minus => 'Minus',
            self::ArrowUp => 'Arrow up',
            self::ArrowDown => 'Arrow down',
            self::ArrowLeft => 'Arrow left',
            self::ArrowRight => 'Arrow right',
            self::AngleUp => 'Angle up',
            self::AngleDown => 'Angle down',
            self::AnglesUp => 'Double angle up',
            self::AnglesDown => 'Double angle down',
            self::ArrowTrendUp => 'Trend up',
            self::ArrowTrendDown => 'Trend down',
            self::Signal => 'Signal full',
            default => $this->name,
        };
    }

    /**
     * A single, purely cosmetic category this icon is filed under in
     * IconPicker.vue's browsable, scrollable list — never a restriction
     * on which *_icon_key slot it can actually be picked for (every icon
     * is a valid choice everywhere; SettingsController validates every
     * *_icon_key with a plain Rule::enum(self::class), not scoped by
     * slot or group). Purely for making a catalog this size easy to
     * browse instead of one long undifferentiated list.
     */
    public function group(): string
    {
        return match ($this) {
            self::Moon, self::Bed, self::CloudMoon, self::Caravan, self::Trailer, self::Tent, self::Campground, self::Couch => 'Sleep & rest',
            self::Briefcase, self::Laptop, self::Building, self::ChartLine, self::City, self::Industry, self::Warehouse, self::BuildingColumns, self::UserTie, self::ScaleBalanced => 'Work & business',
            self::Books, self::Apple, self::School, self::Brain, self::Math, self::Chemistry, self::Science, self::History, self::Geography, self::Computer, self::Graduate, self::GraduationCap => 'School & learning',
            self::Check, self::CalendarCheck, self::CalendarX, self::Clock,
            self::DoorOpen, self::DoorClosed, self::ToggleOn, self::ToggleOff, self::CircleCheck, self::CircleXmark, self::Ban, self::Lock, self::X, self::Alert => 'Time & status',
            self::Users, self::Handshake, self::PeopleGroup, self::Baby, self::Child, self::Crown, self::Ring => 'People & social',
            self::Tree, self::Sun, self::Mountain, self::Leaf, self::Seedling, self::Compass, self::UmbrellaBeach, self::PersonHiking, self::Fire => 'Nature & outdoors',
            self::Cloud, self::CloudRain, self::Snowflake, self::Bolt, self::Umbrella, self::Droplet => 'Weather',
            self::Paw, self::Cat, self::Dog, self::Fish => 'Animals',
            self::Coffee, self::Utensils, self::PizzaSlice, self::MugSaucer, self::IceCream, self::CakeCandles => 'Food & drink',
            self::Dumbbell, self::Futbol, self::Basketball, self::Bicycle, self::PersonRunning, self::PersonSwimming, self::Volleyball => 'Sports & fitness',
            self::Plane, self::Car, self::Train, self::Ship, self::Rocket, self::PaperPlane, self::Map, self::Globe, self::Anchor => 'Travel & transport',
            self::HeartPulse, self::Pills, self::Stethoscope, self::BriefcaseMedical => 'Health',
            self::Wrench, self::Hammer, self::Key, self::House => 'Home & tools',
            self::Music, self::Gamepad, self::Book, self::Palette, self::Paintbrush, self::Camera, self::Film, self::Guitar, self::Drum, self::PuzzlePiece, self::Ticket, self::MasksTheater, self::ChessKnight, self::VR => 'Arts & hobbies',
            self::Signal, self::BatteryFull, self::BatteryThreeQuarters, self::BatteryHalf, self::BatteryQuarter, self::BatteryEmpty,
            self::Signal0, self::Signal1, self::Signal2, self::Signal3, self::TemperatureEmpty, self::TemperatureQuarter, self::TemperatureHalf, self::TemperatureThreeQuarters, self::TemperatureFull, self::GaugeLow, self::GaugeMedium, self::GaugeHigh, self::HourglassStart, self::HourglassHalf, self::HourglassEnd, self::VolumeOff, self::VolumeLow, self::VolumeHigh, self::FaceFrown, self::FaceMeh, self::FaceSmile, self::FaceGrinBeam, self::StarHalf, self::CircleDot, self::CircleHalfStroke, self::TrafficLight, self::Flag, self::FlagCheckered, self::Medal, self::Trophy, self::RankingStar, self::ThumbsDown => 'Levels & ranking',
            self::Equals, self::Minus, self::ArrowUp, self::ArrowDown, self::ArrowLeft, self::ArrowRight, self::AngleUp, self::AngleDown, self::AnglesUp, self::AnglesDown, self::ArrowTrendUp, self::ArrowTrendDown => 'Arrows & direction',
            self::Star, self::Heart, self::Gift, self::Bell, self::ThumbsUp, self::Poop, self::Lightbulb => 'Misc',
        };
    }

    /**
     * Extra search terms beyond label()/the enum's own name — the search
     * box in IconPicker.vue matches against label, key, AND these, so an
     * owner typing "home" still finds "House", "job" still finds
     * "Briefcase", etc. Most icons need none: their own label already is
     * the obvious term someone would type. Deliberately not exhaustive —
     * add to this as real gaps turn up, not speculatively for every case.
     *
     * @return list<string>
     */
    public function keywords(): array
    {
        return match ($this) {
            self::House => ['home'],
            self::Briefcase => ['job', 'office', 'work'],
            self::Bed => ['sleep'],
            self::Moon => ['night', 'sleep'],
            self::GraduationCap, self::Graduate => ['school', 'college', 'university'],
            self::Utensils => ['food', 'eat', 'restaurant', 'dinner'],
            self::MugSaucer => ['coffee', 'tea', 'drink'],
            self::Car => ['drive', 'driving', 'commute'],
            self::Plane => ['flight', 'fly', 'travel'],
            self::Dumbbell => ['gym', 'workout', 'exercise', 'fitness'],
            self::PersonRunning => ['run', 'jog', 'exercise'],
            self::HeartPulse => ['health', 'medical', 'fitness'],
            self::Stethoscope, self::BriefcaseMedical => ['doctor', 'medical', 'health'],
            self::Pills => ['medicine', 'medication', 'health'],
            self::Users, self::PeopleGroup => ['friends', 'family', 'social', 'group'],
            self::Handshake => ['deal', 'meeting', 'agreement'],
            self::Gamepad => ['games', 'gaming', 'video games'],
            self::Music => ['song', 'concert'],
            self::Camera => ['photo', 'photography'],
            self::Palette, self::Paintbrush => ['art', 'painting', 'drawing'],
            self::Book, self::Books => ['reading', 'study'],
            self::Paw, self::Cat, self::Dog => ['pet', 'animal'],
            self::CakeCandles => ['birthday', 'party', 'celebration'],
            self::Gift => ['present', 'birthday', 'celebration'],
            self::Tent, self::Campground, self::Caravan, self::Trailer => ['camping', 'trip', 'vacation'],
            self::UmbrellaBeach => ['vacation', 'holiday', 'beach'],
            self::Sun => ['sunny', 'day', 'weather'],
            self::CloudRain => ['rain', 'weather'],
            self::Snowflake => ['snow', 'winter', 'cold', 'weather'],
            self::Ban, self::Lock => ['blocked', 'unavailable', 'busy'],
            self::CircleXmark, self::X => ['cancel', 'no', 'remove'],
            self::CircleCheck, self::Check => ['yes', 'done', 'confirmed'],
            self::Alert => ['warning', 'important'],
            self::Bell => ['notification', 'reminder', 'alarm'],
            self::Baby, self::Child => ['kid', 'family'],
            self::Ring => ['wedding', 'engagement', 'marriage'],
            self::ChessKnight, self::PuzzlePiece => ['games', 'strategy'],
            self::MasksTheater => ['theatre', 'drama', 'performance'],
            self::Ticket => ['event', 'show', 'concert'],
            self::Lightbulb => ['idea', 'inspiration'],
            self::TrafficLight => ['status', 'red', 'amber', 'green', 'stop', 'go', 'rag'],
            self::Flag => ['marker', 'mark', 'flagged', 'status'],
            self::FlagCheckered => ['finish', 'done', 'race', 'complete'],
            self::VR => ['goggles', 'virtual', 'reality', 'virtual reality', 'steamvr', 'vrchat'],
            self::Signal0 => ['reception', 'bars', 'level', 'none'],
            self::Signal1 => ['reception', 'bars', 'level', 'low'],
            self::Signal2 => ['reception', 'bars', 'level', 'medium'],
            self::Signal3 => ['reception', 'bars', 'level', 'high'],
            self::TemperatureEmpty => ['level', 'cold', 'low', 'temperature'],
            self::TemperatureQuarter => ['level', 'temperature'],
            self::TemperatureHalf => ['level', 'temperature'],
            self::TemperatureThreeQuarters => ['level', 'temperature'],
            self::TemperatureFull => ['level', 'hot', 'high', 'temperature'],
            self::GaugeLow => ['level', 'speed', 'meter'],
            self::GaugeMedium => ['level', 'speed', 'meter'],
            self::GaugeHigh => ['level', 'speed', 'meter'],
            self::HourglassStart => ['time', 'progress', 'level'],
            self::HourglassHalf => ['time', 'progress', 'level'],
            self::HourglassEnd => ['time', 'progress', 'level'],
            self::VolumeOff => ['sound', 'mute', 'level'],
            self::VolumeLow => ['sound', 'quiet', 'level'],
            self::VolumeHigh => ['sound', 'loud', 'level'],
            self::FaceFrown => ['mood', 'rating', 'sad', 'bad'],
            self::FaceMeh => ['mood', 'rating', 'neutral', 'ok'],
            self::FaceSmile => ['mood', 'rating', 'happy', 'good'],
            self::FaceGrinBeam => ['mood', 'rating', 'happy', 'great'],
            self::StarHalf => ['rating', 'half'],
            self::CircleDot => ['fill', 'level', 'radio'],
            self::CircleHalfStroke => ['fill', 'level', 'half', 'contrast'],
            self::Medal => ['rank', 'ranking', 'award', 'first'],
            self::Trophy => ['rank', 'ranking', 'award', 'win'],
            self::RankingStar => ['rank', 'podium', 'leaderboard'],
            self::ThumbsDown => ['dislike', 'rating', 'bad'],
            self::Equals => ['same', 'tie', 'neutral'],
            self::Minus => ['less', 'decrease', 'negative'],
            self::ArrowUp => ['up', 'increase', 'higher'],
            self::ArrowDown => ['down', 'decrease', 'lower'],
            self::ArrowLeft => ['left', 'back'],
            self::ArrowRight => ['right', 'forward', 'next'],
            self::AngleUp => ['chevron', 'up', 'rank', 'higher'],
            self::AngleDown => ['chevron', 'down', 'rank', 'lower'],
            self::AnglesUp => ['chevron', 'up', 'rank', 'promote'],
            self::AnglesDown => ['chevron', 'down', 'rank', 'demote'],
            self::ArrowTrendUp => ['rising', 'increase', 'growth'],
            self::ArrowTrendDown => ['falling', 'decrease', 'decline'],
            self::Signal, self::BatteryFull, self::BatteryThreeQuarters, self::BatteryHalf, self::BatteryQuarter, self::BatteryEmpty => ['level', 'full', 'high'],
            default => [],
        };
    }

    /**
     * Shape consumed by resources/js/free/icon-palette.ts's
     * setIconPalette() — an ordered {key, label, group, keywords}[],
     * sorted alphabetically by label() rather than the enum's own
     * declaration order (which is grouped by when each icon was added,
     * not anything an owner scanning the picker would find meaningful) —
     * IconPicker.vue re-groups this flat list by `group` itself for
     * display.
     *
     * @return list<array{key: string, label: string, group: string, keywords: list<string>}>
     */
    public static function forFrontend(): array
    {
        $cases = self::cases();

        usort($cases, fn (self $a, self $b) => strcasecmp($a->label(), $b->label()));

        return array_map(
            fn (self $case) => ['key' => $case->value, 'label' => $case->label(), 'group' => $case->group(), 'keywords' => $case->keywords()],
            $cases,
        );
    }
}
