<?php

namespace App\Support;

/**
 * Which ColorSwatchKey each *_color_key slot starts on before an owner
 * ever touches it — the actual swatch catalog (label/light/dark hex per
 * key) lives in ColorSwatchKey itself now; this class is just the
 * per-slot "which one is the default" map plus the frontend-facing
 * catalog export.
 */
class ColorPalette
{
    public const DEFAULT_KEYS = [
        'accent' => ColorSwatchKey::Blue->value,
        'secondary' => ColorSwatchKey::Silver->value,
        'free' => ColorSwatchKey::Blue->value,
        // Charcoal, not another gray-ramp step: its light hex (#212529)
        // matches dark-theme.css's own fixed --wtf-color-busy default
        // exactly, and its dark hex (#6c757d) is the darkest option the
        // ramp has — every other step (slate/steel/silver/fog) trends
        // lighter in dark theme, which made an un-customized Unavailable
        // block render far lighter than the reference app's fixed
        // near-black, unreadable-looking wash the CSS default was
        // actually tuned against.
        'busy' => ColorSwatchKey::Charcoal->value,
        'sleep' => ColorSwatchKey::Purple->value,
        'highlighted' => ColorSwatchKey::Amber->value,
        // Plain blue, distinct from Blue/Glaucous (the accent/free
        // default) — matches the source app's own hardcoded Bootstrap
        // primary as closely as this palette's curated hues allow.
        'work' => ColorSwatchKey::Sky->value,
    ];

    /** Shape consumed by resources/js/free/color-palette.ts's setColorPalette(). */
    public static function forFrontend(): array
    {
        return ColorSwatchKey::forFrontend();
    }
}
