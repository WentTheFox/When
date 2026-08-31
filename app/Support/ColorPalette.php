<?php

namespace App\Support;

/**
 * Mirrors resources/js/free/color-palette.ts's key list (only the keys —
 * the actual light/dark hex pairs only ever need to be resolved client-side,
 * where the theme rendering already happens). Keep both files' key lists in
 * sync. now_color is unrelated to this: it stays a raw, theme-independent
 * hex (see SettingsController) since it was never part of the "picked a
 * light-mode color, looks bad in dark mode" problem this palette exists to
 * avoid.
 */
class ColorPalette
{
    public const KEYS = [
        'blue',
        'indigo',
        'purple',
        'pink',
        'red',
        'amber',
        'green',
        'teal',
        'slate',
        'silver',
    ];
}
