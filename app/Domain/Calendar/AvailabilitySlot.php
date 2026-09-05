<?php

namespace App\Domain\Calendar;

use Carbon\CarbonImmutable;

/**
 * A single time range in AvailabilityResult's flat, possibly-overlapping
 * event list — see that class's doc comment for why entries overlap and
 * who resolves that. `type` says which category this slot belongs to
 * (free/unavailable/highlighted/work/school/public/sleep); a single event
 * can produce more than one slot with different types covering the same
 * span (e.g. busy AND highlighted).
 */
final class AvailabilitySlot
{
    public function __construct(
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly string $type,
        /** Start time is unknown/approximate (source title ended in "(?-)", or fully-tentative "(?)"/STATUS:TENTATIVE). Only ever true for unavailable/highlighted/work/school/public types — free/sleep never carry this. */
        public readonly bool $tentativeStart = false,
        /** End time is unknown/approximate (source title ended in "(-?)", or fully-tentative "(?)"/STATUS:TENTATIVE). Only ever true for unavailable/highlighted/work/school/public types — free/sleep never carry this. */
        public readonly bool $tentativeEnd = false,
        /** Freetext preceding "with X"/"w/ X" (e.g. "Dinner"), raw and unlocalized, for a highlighted slot — null unless share_links.show_activity is on for this link, or activityLabel below already covers this event. See ActivityExtractor. For a public slot, this is instead the event's full raw summary verbatim (no extraction pattern applied) — see AvailabilityService::compute. */
        public readonly ?string $activity = null,
        /** The owner's own configured, localized label for this event's matched activity_localization (e.g. "Visiting"/"Hosting", or any other role an owner defined) — see App\Support\LocalizedText. Takes precedence over `activity` above when both are present (see AvailabilityService::compute). Only ever set for a highlighted slot. */
        public readonly ?array $activityLabel = null,
        /** Every configured highlight word that matched (a clause can name more than one person). Only ever set for a highlighted slot. */
        public readonly array $highlightWords = [],
    ) {}

    public function toArray(): array
    {
        return [
            'start' => $this->start->toIso8601String(),
            'end' => $this->end->toIso8601String(),
            'type' => $this->type,
            'tentative_start' => $this->tentativeStart,
            'tentative_end' => $this->tentativeEnd,
            'activity' => $this->activity,
            'activity_label' => $this->activityLabel,
            'highlight_words' => $this->highlightWords,
        ];
    }
}
