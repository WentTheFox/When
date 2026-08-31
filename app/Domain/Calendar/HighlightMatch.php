<?php

namespace App\Domain\Calendar;

/**
 * The result of HighlightMatcher::match — every configured highlight word
 * that matched (a clause can name more than one person, e.g. "Dinner with
 * Charlie, Alice" against words configured for both), and whether the
 * event's title was a "Host X" (the token is visiting the calendar owner)
 * or "Visit X" (the owner is visiting the token) convention rather than an
 * ordinary "with X"/"w/ X" clause.
 */
final class HighlightMatch
{
    /** @param string[] $words Always at least one — HighlightMatcher never returns an empty match. */
    public function __construct(
        public readonly array $words,
        public readonly bool $visiting = false,
        public readonly bool $hosting = false,
    ) {}
}
