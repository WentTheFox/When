<?php

namespace App\Services\Calendar;

use App\Domain\Calendar\RawCalendarItem;
use App\Support\Regex;
use Carbon\CarbonImmutable;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;

/**
 * Parses raw ICS text into normalized {@see RawCalendarItem}s.
 *
 * Bounded to [$rangeStart, $rangeEnd]: past, non-recurring events outside
 * that window are dropped before they're ever materialized, and recurring
 * events are expanded only within it — there's no reason to hold years of
 * calendar history in memory just to compute a forward-looking free/busy
 * result (this app doesn't track past availability at all).
 *
 * IMPORTANT (§0.2): the caller is responsible for never logging $icsBody —
 * this class doesn't log anything itself, but don't wrap calls to it in
 * anything that would.
 */
class IcsParser
{
    /**
     * Regex fragment (no delimiters — same convention as DND/nap/highlight/
     * activity patterns), matched case-insensitively, unanchored except for
     * its own trailing $. Owner-customizable via users.tentative_pattern;
     * this is just the fallback when that's null. A plain literal like
     * "(?)" still works as-is; owners whose own convention differs (e.g. a
     * trailing "[tentative]") can override it the same way as any other
     * pattern here.
     */
    public const DEFAULT_TENTATIVE_TITLE_PATTERN = '\(\?\)\s*$';

    /** @return RawCalendarItem[] */
    public function parse(string $icsBody, CarbonImmutable $rangeStart, CarbonImmutable $rangeEnd, ?string $tentativeTitlePattern = null): array
    {
        /** @var VCalendar $calendar */
        $calendar = Reader::read($icsBody, Reader::OPTION_FORGIVING);

        if (! $calendar instanceof VCalendar) {
            return [];
        }

        // expand() only handles VEVENT/VTODO/VJOURNAL recurrence — it
        // silently drops VFREEBUSY components entirely. VFREEBUSY doesn't
        // use RRULE-based recurrence anyway (just literal period lists), so
        // it's read from the original, unexpanded calendar.
        $vfreebusyComponents = $calendar->select('VFREEBUSY');
        $expandedCalendar = $calendar->expand($rangeStart->toDateTime(), $rangeEnd->toDateTime());

        $items = [];

        $pattern = $tentativeTitlePattern ?: self::DEFAULT_TENTATIVE_TITLE_PATTERN;

        foreach ($expandedCalendar->select('VEVENT') as $vevent) {
            $item = $this->parseVEvent($vevent, $pattern);

            if ($item !== null && $item->end > $rangeStart && $item->start < $rangeEnd) {
                $items[] = $item;
            }
        }

        foreach ($vfreebusyComponents as $vfreebusy) {
            foreach ($this->parseVFreeBusy($vfreebusy) as $item) {
                if ($item->end > $rangeStart && $item->start < $rangeEnd) {
                    $items[] = $item;
                }
            }
        }

        return $items;
    }

    private function parseVEvent(\Sabre\VObject\Component $vevent, string $tentativeTitlePattern): ?RawCalendarItem
    {
        if (! isset($vevent->DTSTART)) {
            return null;
        }

        $start = CarbonImmutable::instance($vevent->DTSTART->getDateTime());
        $end = isset($vevent->DTEND)
            ? CarbonImmutable::instance($vevent->DTEND->getDateTime())
            : $start->addHour();

        $summary = isset($vevent->SUMMARY) ? (string) $vevent->SUMMARY : null;
        $isTentativeStatus = isset($vevent->STATUS) && strtoupper((string) $vevent->STATUS) === 'TENTATIVE';

        // \x01 delimiter, same reasoning as ParsedEvent::matchesEventNamePattern:
        // lets an owner's pattern contain any printable character freely.
        // An invalid pattern fails closed (no match) rather than throwing.
        $delimitedPattern = "\x01".$tentativeTitlePattern."\x01iu";
        $isTentativeTitle = $summary !== null && Regex::tryMatch($delimitedPattern, trim($summary)) !== null;
        // Whatever matched is stripped out too, so it never leaks into
        // downstream DND/nap/highlight/activity matching or gets shown
        // anywhere — same idea as the built-in "(?)" convention, just
        // generalized to whatever pattern actually matched.
        $cleanedSummary = $summary !== null ? preg_replace("\x01\\s*".ltrim($delimitedPattern, "\x01"), '', $summary) : null;

        return new RawCalendarItem(
            uid: isset($vevent->UID) ? (string) $vevent->UID : bin2hex(random_bytes(8)),
            start: $start,
            end: $end,
            componentType: 'VEVENT',
            summary: $cleanedSummary,
            description: isset($vevent->DESCRIPTION) ? (string) $vevent->DESCRIPTION : null,
            location: isset($vevent->LOCATION) ? (string) $vevent->LOCATION : null,
            isTentative: $isTentativeStatus || $isTentativeTitle,
        );
    }

    /** @return RawCalendarItem[] */
    private function parseVFreeBusy(\Sabre\VObject\Component $vfreebusy): array
    {
        $items = [];

        foreach ($vfreebusy->select('FREEBUSY') as $freebusy) {
            $fbType = isset($freebusy['FBTYPE']) ? strtoupper((string) $freebusy['FBTYPE']) : 'BUSY';

            if ($fbType === 'FREE') {
                continue;
            }

            foreach (explode(',', (string) $freebusy) as $period) {
                [$startRaw, $endRaw] = array_pad(explode('/', $period, 2), 2, null);

                if ($startRaw === null || $endRaw === null) {
                    continue;
                }

                $start = CarbonImmutable::parse($startRaw);
                $end = str_starts_with($endRaw, 'P')
                    ? $start->add(new \DateInterval($endRaw))
                    : CarbonImmutable::parse($endRaw);

                $items[] = new RawCalendarItem(
                    uid: bin2hex(random_bytes(8)),
                    start: $start,
                    end: $end,
                    componentType: 'VFREEBUSY',
                    isTentative: $fbType === 'BUSY-TENTATIVE',
                );
            }
        }

        return $items;
    }
}
