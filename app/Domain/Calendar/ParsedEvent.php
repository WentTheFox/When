<?php

namespace App\Domain\Calendar;

use App\Services\Calendar\AvailabilityService;
use App\Services\Calendar\IcsParser;
use App\Support\Regex;
use Carbon\CarbonImmutable;

/**
 * A normalized calendar event, decoupled from the ICS format so
 * {@see AvailabilityService} can be tested without
 * any ICS parsing details, and {@see IcsParser} can
 * be tested without any availability-computation details.
 */
final class ParsedEvent
{
    public function __construct(
        public readonly string $uid,
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly ?string $summary = null,
        public readonly ?string $description = null,
        public readonly ?string $location = null,
        /**
         * True when this event came from a feed/component that never
         * exposes real titles (a VFREEBUSY block, or a "free_busy_only"
         * classified feed) — §5.0's signal to skip title-based matching
         * entirely for this event rather than matching against a fake
         * generic summary like "Busy".
         */
        public readonly bool $isFreeBusyOnly = false,
        /** Start time is unknown/approximate — from "(?-)" or a fully-tentative "(?)"/STATUS:TENTATIVE match. */
        public readonly bool $tentativeStart = false,
        /** End time is unknown/approximate — from "(-?)" or a fully-tentative "(?)"/STATUS:TENTATIVE match. */
        public readonly bool $tentativeEnd = false,
        /**
         * True when the title matched IcsParser's public_event_pattern —
         * decided (and the matched marker stripped out of $summary above)
         * at parse time, same Flag treatment as tentativeStart/tentativeEnd
         * above, rather than a plain matchesEventNamePattern() check like
         * dnd/nap/work/school: by the time this event reaches
         * AvailabilityService::compute(), the marker text is already gone
         * from $summary, so there's nothing left here to match against.
         */
        public readonly bool $isPublicEventTitle = false,
    ) {}

    /**
     * DND/nap event-name matching. `$pattern` is a regex fragment (no
     * delimiters — the owner types just the pattern body), matched
     * case-insensitively, unanchored, against the summary. A plain literal
     * string like "Sleep" still works as-is; owners who want more can use
     * real regex, e.g. `^(Sleep|Nap)` or `Focus.*Block`.
     *
     * An invalid pattern is treated as "never matches" rather than throwing
     * — event-name settings are free text an owner can mistype, and a typo
     * shouldn't take down availability computation for every viewer.
     *
     * Never matches a free-busy-only event, regardless of pattern — its
     * summary (if any) is a fake generic placeholder like "Busy", not real
     * title text, so evaluating a DND/nap pattern against it would either
     * never fire or fire on coincidence rather than intent.
     */
    public function matchesEventNamePattern(?string $pattern): bool
    {
        if ($pattern === null || $pattern === '' || $this->summary === null || $this->isFreeBusyOnly) {
            return false;
        }

        // \x01 as the delimiter (rather than a printable character like /
        // or #) so an owner's pattern can freely contain any printable
        // character without needing to escape a delimiter collision.
        return Regex::tryMatch("\x01".$pattern."\x01iu", $this->summary) !== null;
    }
}
