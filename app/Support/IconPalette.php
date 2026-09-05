<?php

namespace App\Support;

/**
 * Which IconKey each *_icon_key slot starts on before an owner ever
 * touches it — the actual icon catalog (label per key) lives in IconKey
 * itself now; this class is just the per-slot "which one is the default"
 * map plus the frontend-facing catalog export.
 */
class IconPalette
{
    /**
     * Every slot's default is a sensible match for what it represents
     * (moon for sleep, briefcase for work, ...) but nothing stops an owner
     * from picking any icon for any slot — same free-reassignment spirit
     * as ColorPalette::DEFAULT_KEYS, this is just a starting point.
     */
    public const DEFAULT_KEYS = [
        'free' => IconKey::Check->value,
        'busy' => IconKey::Ban->value,
        'work' => IconKey::Briefcase->value,
        'school' => IconKey::School->value,
        'public' => IconKey::PeopleGroup->value,
        'sleep' => IconKey::Moon->value,
        'highlighted' => IconKey::Star->value,
    ];

    /** Shape consumed by resources/js/free/icon-palette.ts's setIconPalette(). */
    public static function forFrontend(): array
    {
        return IconKey::forFrontend();
    }
}
