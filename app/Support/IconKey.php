<?php

namespace App\Support;

/**
 * The app's fixed, curated icon options for the five calendar block types
 * (free/busy/work/sleep/highlighted) — not a free-form icon picker, same
 * "curated list, not arbitrary input" spirit as ColorSwatchKey. Every key
 * here is this app's own name, deliberately never a raw Font Awesome icon
 * identifier: FA has renamed icons across major versions before (the
 * "coffee mug" icon alone has gone by faCoffee and faMugHot across
 * FA5/FA6), and a value stored in `users.*_icon_key` outlives any one FA
 * version this app happens to be built against. The actual key -> FA-icon
 * mapping lives entirely client-side, in resources/js/free/icon-
 * palette.ts's ICON_KEY_TO_FA — this enum only hands the frontend the KEY
 * + a display label() for the picker UI, never an icon reference of its
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
 * label() lives right next to it instead of in a lookup table that could
 * silently drift out of sync with a separate key array.
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
        };
    }

    /** Shape consumed by resources/js/free/icon-palette.ts's setIconPalette() — an ordered {key, label}[]. */
    public static function forFrontend(): array
    {
        return array_map(
            fn (self $case) => ['key' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
