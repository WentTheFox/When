<?php

namespace App\Support;

/**
 * The curated options for `users.now_color_key` — the current-time
 * line/dot on the week/month views. Same KEY-into-a-curated-palette
 * scheme as ColorSwatchKey/IconKey now (an earlier version of this let an
 * owner submit any raw `#rrggbb` hex directly; that's deliberately gone
 * — a free-form color risked landing on something that reads badly
 * against one theme, exactly the problem ColorSwatchKey's own light/dark
 * pairing exists to avoid, and there's no reason the current-time marker
 * should be exempt from it).
 *
 * Deliberately loud, fully-saturated primaries/secondaries, chosen to
 * never overlap a ColorSwatchKey — every one of those is a muted,
 * WCAG-tuned hue meant to sit behind readable text, not to scream for
 * attention, so a preset here would risk visually blending into a
 * same-colored event block instead of standing out against it if it were
 * also available as, say, the Highlighted color. Each still gets its own
 * light/dark pair rather than one flat hex — a pure, unadjusted primary
 * can have genuinely poor contrast against one theme even while looking
 * fine against the other (pure blue #0000ff barely registers against a
 * near-black dark background; pure yellow/cyan/lime are similarly weak
 * against a white one) — lightened/darkened per theme the same way every
 * other slot already is, not just left "theme-independent" the way this
 * used to be documented.
 *
 * A backed enum rather than an assoc array — self::cases() is already the
 * ordered key list (no separate KEYS constant to keep in sync by hand),
 * and each case's own label()/light()/dark() lives right next to it
 * instead of in a parallel lookup table.
 */
enum NowColorPresetKey: string
{
    case Red = 'red';
    case Orange = 'orange';
    case Yellow = 'yellow';
    case Lime = 'lime';
    case Cyan = 'cyan';
    case Blue = 'blue';
    case Magenta = 'magenta';

    public function label(): string
    {
        return match ($this) {
            self::Red => 'Red',
            self::Orange => 'Orange',
            self::Yellow => 'Yellow',
            self::Lime => 'Lime',
            self::Cyan => 'Cyan',
            self::Blue => 'Blue',
            self::Magenta => 'Magenta',
        };
    }

    /** Tuned for contrast against a light/white-ish background — the pure hue where that's already fine, darkened/deepened where it isn't (yellow/lime/cyan). */
    public function light(): string
    {
        return match ($this) {
            self::Red => '#ff0000',
            self::Orange => '#ff7f00',
            self::Yellow => '#b8a300',
            self::Lime => '#4d9900',
            self::Cyan => '#00838f',
            self::Blue => '#0000ff',
            self::Magenta => '#ff00ff',
        };
    }

    /** Tuned for contrast against a near-black dark background — the pure hue where that's already fine, lightened where it isn't (blue is the classic offender: low luminance, barely visible on near-black). */
    public function dark(): string
    {
        return match ($this) {
            self::Red => '#ff0000',
            self::Orange => '#ff7f00',
            self::Yellow => '#ffff00',
            self::Lime => '#66ff00',
            self::Cyan => '#00ffff',
            self::Blue => '#4d79ff',
            self::Magenta => '#ff00ff',
        };
    }

    public static function default(): self
    {
        return self::Red;
    }

    /** Shape consumed by resources/js/free/now-color-presets.ts's setNowColorPresets() — an ordered {key, label, light, dark}[]. */
    public static function forFrontend(): array
    {
        return array_map(
            fn (self $case) => ['key' => $case->value, 'label' => $case->label(), 'light' => $case->light(), 'dark' => $case->dark()],
            self::cases(),
        );
    }
}
