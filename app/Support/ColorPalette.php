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
        // matches dark-theme.css's own fixed --app-color-busy default
        // exactly, and its dark hex (#6c757d) is the darkest option the
        // ramp has — every other step (slate/steel/silver/fog) trends
        // lighter in dark theme, which made an un-customized Unavailable
        // block render far lighter than the reference app's fixed
        // near-black, unreadable-looking wash the CSS default was
        // actually tuned against.
        'busy' => ColorSwatchKey::Charcoal->value,
        'sleep' => ColorSwatchKey::Purple->value,
        // Gold, not Amber — a highlighted event is meant to visually pop
        // more than the rest of the calendar, and gold reads as more
        // purely "attention-grabbing yellow" than Amber's warmer,
        // more subdued orange-yellow lean.
        'highlighted' => ColorSwatchKey::Gold->value,
        // Brown — the color most commonly associated with "work" in
        // everyday use (desks, wood, coffee).
        'work' => ColorSwatchKey::Brown->value,
        // Green — a common school/education association (chalkboards,
        // classic school-supply green).
        'school' => ColorSwatchKey::Green->value,
        // Slate, not Charcoal/Silver — a public event should read as
        // deliberately neutral/monochrome (distinct from both busy's
        // near-black and secondary's own default gray), while its full
        // raw title still does the work of conveying what it actually is.
        'public' => ColorSwatchKey::Slate->value,
    ];

    /** Shape consumed by resources/js/free/color-palette.ts's setColorPalette(). */
    public static function forFrontend(): array
    {
        return ColorSwatchKey::forFrontend();
    }
}
