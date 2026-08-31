<?php

namespace App\Support;

/**
 * The single source of truth for the app's fixed, curated color options —
 * not a free-form hex picker. Letting an owner type any arbitrary hex
 * meant a color picked while previewing one theme often read badly on the
 * other (a light pastel chosen in light mode nearly disappears against
 * dark mode's own dark background, and vice versa). Every swatch instead
 * pairs a light-theme hex with a dark-theme hex.
 *
 * The client never sees or sends a hex for these six slots (accent/
 * secondary/free/busy/sleep/highlighted) — only a KEY from here, both for
 * display (this whole map is shared to every page via
 * HandleInertiaRequests so the frontend can render/resolve swatches
 * without duplicating the hex values) and for storage (SettingsController
 * validates every incoming *_color_key against self::KEYS). now_color is
 * unrelated to this and stays a raw, owner-supplied, theme-independent
 * hex — it was never part of the "picked a light-mode color, looks bad in
 * dark mode" problem this palette exists to avoid.
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
 */
class ColorPalette
{
    public const SWATCHES = [
        'red' => ['label' => 'Red', 'light' => '#e03131', 'dark' => '#ff8787'],
        'orange' => ['label' => 'Orange', 'light' => '#ee5212', 'dark' => '#ff8c47'],
        'amber' => ['label' => 'Amber', 'light' => '#f08c00', 'dark' => '#ffc107'],
        'lime' => ['label' => 'Lime', 'light' => '#72ab17', 'dark' => '#9aea3b'],
        'green' => ['label' => 'Green', 'light' => '#2f9e44', 'dark' => '#69db7c'],
        'jade' => ['label' => 'Jade', 'light' => '#1c9d72', 'dark' => '#67e2b6'],
        'teal' => ['label' => 'Teal', 'light' => '#0c8599', 'dark' => '#66d9e8'],
        'sky' => ['label' => 'Sky', 'light' => '#2b7fb3', 'dark' => '#61a8d2'],
        // Labeled "Glaucous" rather than "Blue" — this app's own signature
        // hue (the default accent/free color) is a specific dusty
        // blue-gray, not a generic blue, and the palette has plainer blues
        // (cornflower, sky) already covering that name.
        'blue' => ['label' => 'Glaucous', 'light' => '#6181b6', 'dark' => '#6181b6'],
        'cornflower' => ['label' => 'Cornflower', 'light' => '#5e77bb', 'dark' => '#6d83c1'],
        'indigo' => ['label' => 'Indigo', 'light' => '#5c6bc0', 'dark' => '#7986cb'],
        'violet' => ['label' => 'Violet', 'light' => '#5b4fc0', 'dark' => '#7a74e5'],
        'purple' => ['label' => 'Purple', 'light' => '#6f42c1', 'dark' => '#9775fa'],
        'magenta' => ['label' => 'Magenta', 'light' => '#cc3acb', 'dark' => '#ef6df5'],
        'pink' => ['label' => 'Pink', 'light' => '#d6336c', 'dark' => '#f06595'],
        'rose' => ['label' => 'Rose', 'light' => '#db3250', 'dark' => '#f9758c'],
        'charcoal' => ['label' => 'Charcoal', 'light' => '#212529', 'dark' => '#6c757d'],
        'slate' => ['label' => 'Slate', 'light' => '#343a40', 'dark' => '#adb5bd'],
        'steel' => ['label' => 'Steel', 'light' => '#495057', 'dark' => '#ced4da'],
        'silver' => ['label' => 'Silver', 'light' => '#6c757d', 'dark' => '#dee2e6'],
        'fog' => ['label' => 'Fog', 'light' => '#939aa1', 'dark' => '#e9ecef'],
    ];

    public const DEFAULT_KEYS = [
        'accent' => 'blue',
        'secondary' => 'silver',
        'free' => 'blue',
        // 'charcoal', not another ramp step: its light hex (#212529) matches
        // dark-theme.css's own fixed --wtf-color-busy default exactly, and
        // its dark hex (#6c757d) is the darkest option the ramp has — every
        // other step (slate/steel/silver/fog) trends lighter in dark theme,
        // which made an un-customized Unavailable block render far lighter
        // than the reference app's fixed near-black, unreadable-looking
        // wash the CSS default was actually tuned against.
        'busy' => 'charcoal',
        'sleep' => 'purple',
        'highlighted' => 'amber',
    ];

    public const KEYS = [
        'red', 'orange', 'amber', 'lime', 'green', 'jade', 'teal', 'sky',
        'blue', 'cornflower', 'indigo', 'violet', 'purple', 'magenta', 'pink', 'rose',
        'charcoal', 'slate', 'steel', 'silver', 'fog',
    ];

    /** Shape consumed by resources/js/free/color-palette.ts's setColorPalette() — an ordered {key, label, light, dark}[], not the assoc SWATCHES map (JSON object key order isn't guaranteed the way array order is). */
    public static function forFrontend(): array
    {
        return array_map(
            fn (string $key) => ['key' => $key, ...self::SWATCHES[$key]],
            self::KEYS,
        );
    }
}
