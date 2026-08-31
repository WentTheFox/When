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
        /** Whether the source event's title ended in "(?)". Only ever true within highlighted/unavailable — free/sleep are never tentative. */
        public readonly bool $tentative = false,
        /** Freetext preceding "with X"/"w/ X" (e.g. "Dinner"). Only ever set within highlighted. Null unless share_links.show_activity is on for this link. See ActivityExtractor. */
        public readonly ?string $activity = null,
        /** True when the event title was "Host <token>" — the token is visiting the calendar owner. Only ever set within highlighted. */
        public readonly bool $visiting = false,
        /** True when the event title was "Visit <token>" — the calendar owner is visiting the token. Only ever set within highlighted. */
        public readonly bool $hosting = false,
        /** Every configured highlight word that matched (a clause can name more than one person). Only ever set within highlighted. */
        public readonly array $highlightWords = [],
    ) {}

    public function toArray(): array
    {
        return [
            'start' => $this->start->toIso8601String(),
            'end' => $this->end->toIso8601String(),
            'tentative' => $this->tentative,
            'activity' => $this->activity,
            'visiting' => $this->visiting,
            'hosting' => $this->hosting,
            'highlight_words' => $this->highlightWords,
        ];
    }
}
