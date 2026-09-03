<?php

namespace App\Support;

/**
 * The app's fixed, curated color options — not a free-form hex picker.
 * Letting an owner type any arbitrary hex meant a color picked while
 * previewing one theme often read badly on the other (a light pastel
 * chosen in light mode nearly disappears against dark mode's own dark
 * background, and vice versa). Every swatch instead pairs a light-theme
 * hex with a dark-theme hex.
 *
 * The client never sees or sends a hex for any *_color_key slot — only a
 * KEY from here, both for display (ColorPalette::forFrontend() shares
 * this whole catalog to every page via HandleInertiaRequests so the
 * frontend can render/resolve swatches without duplicating the hex
 * values) and for storage (SettingsController/ConnectionSourceCategory
 * Controller validate every incoming *_color_key with Rule::enum(self::
 * class)). now_color_key is a separate, smaller palette of its own (see
 * NowColorPresetKey) — deliberately loud/saturated colors kept out of
 * this one so the current-time marker never blends into a same-colored
 * event block, but validated and resolved the same KEY-based way.
 *
 * The 8 original chromatic hues (blue/indigo/purple/pink/red/amber/green/
 * teal — this app's own pre-existing accent/sleep/highlighted/busy/
 * secondary defaults, plus the rest spaced to fill the hue wheel) are
 * doubled to 16 by inserting one hue exactly between each adjacent pair
 * (circular hue mean, averaged saturation/lightness) — cornflower,
 * violet, magenta, rose, orange, lime, jade, sky. The 2 original grays
 * (slate/silver) become a 5-step ramp (charcoal/slate/steel/silver/fog).
 *
 * Every swatch is WCAG-AA verified (>=4.5:1), not just eyeballed: each hex
 * is checked as it's ACTUALLY rendered — color-mix(in srgb, hue 65%,
 * --wtf-text 35%) (dark-theme.css's --wtf-fcal-text-* formula) against
 * --wtf-bg, separately for light-hex-vs-light-theme and
 * dark-hex-vs-dark-theme (a block label's own background is the page
 * background, since blocks tile directly on it) — not the raw swatch hex
 * alone, which would understate real contrast since the rendered text
 * color is always blended toward --wtf-text. lime and fog needed the
 * generated hue's default lightness pulled in from the hue-averaging /
 * gray-ramp math to clear 4.5:1; every other swatch already cleared it
 * with no adjustment.
 *
 * A backed enum rather than an assoc array plus a parallel KEYS list —
 * self::cases() is already the ordered key list, and each case's own
 * label()/light()/dark() lives right next to it instead of in a lookup
 * table that could silently drift out of sync with a separate key array.
 */
enum ColorSwatchKey: string
{
    case Red = 'red';
    case Orange = 'orange';
    case Amber = 'amber';
    case Lime = 'lime';
    case Green = 'green';
    case Jade = 'jade';
    case Teal = 'teal';
    case Sky = 'sky';
    // Labeled "Glaucous" rather than "Blue" — this app's own signature
    // hue (the default accent/free color) is a specific dusty blue-gray,
    // not a generic blue, and the palette has plainer blues (Cornflower,
    // Sky) already covering that name.
    case Blue = 'blue';
    case Cornflower = 'cornflower';
    case Indigo = 'indigo';
    case Violet = 'violet';
    case Purple = 'purple';
    case Magenta = 'magenta';
    case Pink = 'pink';
    case Rose = 'rose';
    case Charcoal = 'charcoal';
    case Slate = 'slate';
    case Steel = 'steel';
    case Silver = 'silver';
    case Fog = 'fog';

    public function label(): string
    {
        return match ($this) {
            self::Red => 'Red',
            self::Orange => 'Orange',
            self::Amber => 'Amber',
            self::Lime => 'Lime',
            self::Green => 'Green',
            self::Jade => 'Jade',
            self::Teal => 'Teal',
            self::Sky => 'Sky',
            self::Blue => 'Glaucous',
            self::Cornflower => 'Cornflower',
            self::Indigo => 'Indigo',
            self::Violet => 'Violet',
            self::Purple => 'Purple',
            self::Magenta => 'Magenta',
            self::Pink => 'Pink',
            self::Rose => 'Rose',
            self::Charcoal => 'Charcoal',
            self::Slate => 'Slate',
            self::Steel => 'Steel',
            self::Silver => 'Silver',
            self::Fog => 'Fog',
        };
    }

    public function light(): string
    {
        return match ($this) {
            self::Red => '#e03131',
            self::Orange => '#ee5212',
            self::Amber => '#f08c00',
            self::Lime => '#72ab17',
            self::Green => '#2f9e44',
            self::Jade => '#1c9d72',
            self::Teal => '#0c8599',
            self::Sky => '#2b7fb3',
            self::Blue => '#6181b6',
            self::Cornflower => '#5e77bb',
            self::Indigo => '#5c6bc0',
            self::Violet => '#5b4fc0',
            self::Purple => '#6f42c1',
            self::Magenta => '#cc3acb',
            self::Pink => '#d6336c',
            self::Rose => '#db3250',
            self::Charcoal => '#212529',
            self::Slate => '#343a40',
            self::Steel => '#495057',
            self::Silver => '#6c757d',
            self::Fog => '#939aa1',
        };
    }

    public function dark(): string
    {
        return match ($this) {
            self::Red => '#ff8787',
            self::Orange => '#ff8c47',
            self::Amber => '#ffc107',
            self::Lime => '#9aea3b',
            self::Green => '#69db7c',
            self::Jade => '#67e2b6',
            self::Teal => '#66d9e8',
            self::Sky => '#61a8d2',
            self::Blue => '#6181b6',
            self::Cornflower => '#6d83c1',
            self::Indigo => '#7986cb',
            self::Violet => '#7a74e5',
            self::Purple => '#9775fa',
            self::Magenta => '#ef6df5',
            self::Pink => '#f06595',
            self::Rose => '#f9758c',
            self::Charcoal => '#6c757d',
            self::Slate => '#adb5bd',
            self::Steel => '#ced4da',
            self::Silver => '#dee2e6',
            self::Fog => '#e9ecef',
        };
    }

    /** Shape consumed by resources/js/free/color-palette.ts's setColorPalette() — an ordered {key, label, light, dark}[]. */
    public static function forFrontend(): array
    {
        return array_map(
            fn (self $case) => ['key' => $case->value, 'label' => $case->label(), 'light' => $case->light(), 'dark' => $case->dark()],
            self::cases(),
        );
    }
}
