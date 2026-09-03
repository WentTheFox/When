<?php

namespace App\Support;

/**
 * Shared by ColorSwatchKey/NowColorPresetKey's forFrontend() — both hand
 * their picker a hue-sorted list (grayscale entries last) rather than
 * whatever order the enum happened to declare cases in, so adding a new
 * swatch/preset later doesn't require also manually re-slotting it into
 * the "right" position among the others.
 */
class ColorMath
{
    /**
     * Standard RGB->HSL conversion, but only the two components the sort
     * above actually needs. Hue is in degrees [0, 360); saturation is
     * [0, 1], with a perfectly gray hex (R=G=B, delta=0) returning hue 0
     * — meaningless for a gray, but harmless since sortKey() below always
     * checks saturation first and never lets a gray's arbitrary hue value
     * affect ordering.
     *
     * @return array{0: float, 1: float} [hue, saturation]
     */
    public static function hueAndSaturation(string $hex): array
    {
        $hex = ltrim($hex, '#');
        // /255.0, not /255 — PHP's `/` returns an int when both operands
        // are int and evenly divisible (0/255 === 0, the int), so a pure
        // black or white channel would silently stay an int here; the
        // strict `$delta === 0.0` check just below then never matches an
        // int 0, and every downstream case with a zero channel anywhere
        // (any pure black/white, and this app's own Monochrome preset)
        // falls through into a division by zero instead of the early
        // return. Forcing a float divisor keeps every result a real float.
        $r = hexdec(substr($hex, 0, 2)) / 255.0;
        $g = hexdec(substr($hex, 2, 2)) / 255.0;
        $b = hexdec(substr($hex, 4, 2)) / 255.0;

        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $delta = $max - $min;

        if ($delta === 0.0) {
            return [0.0, 0.0];
        }

        $lightness = ($max + $min) / 2;
        $saturation = $delta / (1 - abs(2 * $lightness - 1));

        $hue = match ($max) {
            $r => fmod(($g - $b) / $delta, 6),
            $g => (($b - $r) / $delta) + 2,
            default => (($r - $g) / $delta) + 4,
        };

        $hue *= 60;

        if ($hue < 0) {
            $hue += 360;
        }

        return [$hue, $saturation];
    }

    /**
     * (max-min)/255 across the three raw channels — deliberately NOT the
     * same as hueAndSaturation()'s HSL saturation, which divides that
     * same delta by (1 - |2L-1|) and therefore blows up toward 1.0 for
     * *any* nonzero channel spread once lightness gets close to 0 or 1
     * (this app's own near-black/near-white grays — Charcoal #212529,
     * Fog #e9ecef — are a slightly cool-tinted, not perfectly neutral,
     * gray for exactly this "grays are also the extreme-lightness
     * swatches" reason, so this bites in practice, not just in theory).
     * Raw chroma has no such blowup: every one of this app's five true
     * grays sits at 0.03-0.07, and the least-saturated real hue (Blue)
     * still sits at 0.33 — nowhere near each other, so a single fixed
     * threshold cleanly tells them apart where HSL saturation can't.
     */
    public static function chroma(string $hex): float
    {
        $hex = ltrim($hex, '#');
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        return (max($r, $g, $b) - min($r, $g, $b)) / 255.0;
    }

    /**
     * Sort key for a hue-sorted, grayscale-last ordering: a 2-tuple where
     * the first element (0 or 1) puts every chromatic swatch before every
     * grayscale one regardless of hue, and the second is the actual hue
     * angle for chromatic entries — comparing these tuples with <=> sorts
     * exactly that way without needing a custom multi-step comparator at
     * every call site.
     *
     * @return array{0: int, 1: float}
     */
    public static function sortKey(string $hex, float $grayscaleChromaThreshold = 0.15): array
    {
        [$hue] = self::hueAndSaturation($hex);

        return [self::chroma($hex) < $grayscaleChromaThreshold ? 1 : 0, $hue];
    }
}
