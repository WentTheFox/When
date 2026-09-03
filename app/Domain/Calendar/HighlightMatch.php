<?php

namespace App\Domain\Calendar;

/**
 * The result of HighlightMatcher::match — every configured highlight word
 * that matched (a clause can name more than one person, e.g. "Dinner with
 * Charlie, Alice" against words configured for both), plus the resolved
 * App\Support\LocalizedText label when the event's title matched one of
 * the owner's configured activity_localization (e.g. the old hardcoded "Host X"/
 * "Visit X" convention, now just two of an owner-configurable list) rather
 * than an ordinary "with X"/"w/ X" clause. See AvailabilityService::
 * compute() for how this interacts with ActivityExtractor's own raw-
 * freetext activity extraction — activityLabel wins when both apply.
 */
final class HighlightMatch
{
    /**
     * @param  string[]  $words  Always at least one — HighlightMatcher never returns an empty match.
     * @param  array<string, string>|null  $activityLabel
     */
    public function __construct(
        public readonly array $words,
        public readonly ?array $activityLabel = null,
    ) {}
}
