<?php

namespace App\Domain\Calendar;

use Carbon\CarbonImmutable;

/**
 * A single time range in one of AvailabilityResult's four categorized,
 * possibly-overlapping arrays (free/highlighted/unavailable/sleep) — see
 * that class's doc comment for why they overlap and who resolves that.
 * There's deliberately no `type` field here (unlike the old single-list
 * design): which array a slot lives in already says that.
 */
final class AvailabilitySlot
{
    public function __construct(
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        /** Start time is unknown/approximate (source title ended in "(?-)", or fully-tentative "(?)"/STATUS:TENTATIVE). Only ever true within highlighted/unavailable — free/sleep never carry this. */
        public readonly bool $tentativeStart = false,
        /** End time is unknown/approximate (source title ended in "(-?)", or fully-tentative "(?)"/STATUS:TENTATIVE). Only ever true within highlighted/unavailable — free/sleep never carry this. */
        public readonly bool $tentativeEnd = false,
        /** Freetext preceding "with X"/"w/ X" (e.g. "Dinner"), raw and unlocalized. Only ever set within highlighted. Null unless share_links.show_activity is on for this link, or activityLabel below already covers this event. See ActivityExtractor. */
        public readonly ?string $activity = null,
        /** The owner's own configured, localized label for this event's matched activity_role (e.g. "Visiting"/"Hosting", or any other role an owner defined) — see App\Support\LocalizedText. Takes precedence over `activity` above when both are present (see AvailabilityService::compute). Only ever set within highlighted. */
        public readonly ?array $activityLabel = null,
        /** Every configured highlight word that matched (a clause can name more than one person). Only ever set within highlighted. */
        public readonly array $highlightWords = [],
    ) {}

    public function toArray(): array
    {
        return [
            'start' => $this->start->toIso8601String(),
            'end' => $this->end->toIso8601String(),
            'tentative_start' => $this->tentativeStart,
            'tentative_end' => $this->tentativeEnd,
            'activity' => $this->activity,
            'activity_label' => $this->activityLabel,
            'highlight_words' => $this->highlightWords,
        ];
    }
}
