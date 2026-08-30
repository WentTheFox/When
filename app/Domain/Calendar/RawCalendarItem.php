<?php

namespace App\Domain\Calendar;

use Carbon\CarbonImmutable;

/**
 * The intermediate form between raw ICS parsing and the normalized
 * {@see ParsedEvent} — kept separate so {@see \App\Services\Calendar\FeedClassifier}
 * can inspect componentType/summary presence before generic-summary
 * detection collapses that into a single isFreeBusyOnly flag.
 */
final class RawCalendarItem
{
    public function __construct(
        public readonly string $uid,
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly string $componentType, // 'VEVENT' | 'VFREEBUSY'
        public readonly ?string $summary = null,
        public readonly ?string $description = null,
        public readonly ?string $location = null,
        public readonly bool $isTentative = false,
    ) {}
}
