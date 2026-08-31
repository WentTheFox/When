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
 * A conservative opt-in: a null/blank pattern means "extract nothing,"
 * same convention as ParsedEvent::matchesEventNamePattern for dnd/nap,
 * rather than silently falling back to DEFAULT_PATTERN. This is
 * deliberately the opposite of HighlightMatcher's own clause pattern,
 * which does fall back to a default when unset — the activity clause
 * hands viewers freetext straight out of the owner's own event titles,
 * so it shouldn't turn itself on just because the owner never visited
 * this one settings field.
 */
class ActivityExtractor
{
    /** Not a functional fallback — see the class doc comment. Only ever used as the settings form's placeholder/suggested-starting-point text. */
    public const DEFAULT_PATTERN = '^(.*?)\b(?:with|w\/)';

    public function extract(string $text, ?string $pattern = null): ?string
    {
        if ($pattern === null || $pattern === '') {
            return null;
        }

        $matches = Regex::tryMatch("\x01".$pattern."\x01iu", $text);

        if ($matches === null || ! isset($matches[1])) {
            return null;
        }

        $activity = rtrim($matches[1]);

        return $activity === '' ? null : $activity;
    }
}
