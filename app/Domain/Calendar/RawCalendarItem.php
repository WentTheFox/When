<?php

namespace App\Domain\Calendar;

use App\Services\Calendar\FeedClassifier;
use Carbon\CarbonImmutable;

/**
 * The intermediate form between raw ICS parsing and the normalized
 * {@see ParsedEvent} — kept separate so {@see FeedClassifier}
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
        public readonly bool $tentativeStart = false,
        public readonly bool $tentativeEnd = false,
        /** Title matched IcsParser's public-event marker pattern — already stripped out of $summary above by the time this is set. */
        public readonly bool $isPublicEventTitle = false,
        /**
         * The repeat rule of the series this occurrence belongs to — unit is one of day|week|month|year, interval
         * the RRULE's INTERVAL (default 1). Null for a one-off event, a VFREEBUSY period, or a series with no
         * RRULE-expressible frequency (RDATE-only, sub-daily). Has to be read off the master VEVENT before
         * expansion, since expanded instances no longer carry their RRULE.
         *
         * @var array{unit: string, interval: int}|null
         */
        public readonly ?array $recurrence = null,
    ) {}
}
