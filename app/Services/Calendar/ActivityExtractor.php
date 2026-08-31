<?php

namespace App\Services\Calendar;

use App\Support\Regex;

/**
 * Extracts the freetext "activity" prefix from a "with X" / "w/ X" event
 * title — e.g. "Dinner" from "Dinner with Alice" — independent of
 * HighlightMatcher's own clause pattern: its own toggle
 * (share_links.show_activity), its own regex (users.activity_clause_
 * pattern). Deliberately only ever invoked on an event that already
 * matched a highlight word (see AvailabilityService::compute) — that
 * bounds the exposure to titles the owner already opted into surfacing
 * *something* from, rather than leaking freetext off of every busy block.
 *
 * On by default (§5.2's pre-commit preview is the owner's chance to catch
 * a titling convention that leaks more than intended, before any link is
 * live — see PLAN.md §5.2), not a conservative opt-in.
 */
class ActivityExtractor
{
    public const DEFAULT_PATTERN = '^(.*?)\b(?:with|w\/)';

    public function extract(string $text, ?string $pattern = null): ?string
    {
        $regex = $pattern ?: self::DEFAULT_PATTERN;

        $matches = Regex::tryMatch("\x01".$regex."\x01iu", $text);

        if ($matches === null || ! isset($matches[1])) {
            return null;
        }

        $activity = rtrim($matches[1]);

        return $activity === '' ? null : $activity;
    }
}
