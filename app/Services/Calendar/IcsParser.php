<?php

namespace App\Services\Calendar;

use App\Domain\Calendar\RawCalendarItem;
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
    /** @return RawCalendarItem[] */
    public function parse(string $icsBody, CarbonImmutable $rangeStart, CarbonImmutable $rangeEnd): array
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

        foreach ($expandedCalendar->select('VEVENT') as $vevent) {
            $item = $this->parseVEvent($vevent);

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

    private function parseVEvent(\Sabre\VObject\Component $vevent): ?RawCalendarItem
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
        $isTentativeTitle = $summary !== null && preg_match('/\(\?\)\s*$/', trim($summary)) === 1;

        return new RawCalendarItem(
            uid: isset($vevent->UID) ? (string) $vevent->UID : bin2hex(random_bytes(8)),
            start: $start,
            end: $end,
            componentType: 'VEVENT',
            summary: $summary !== null ? preg_replace('/\s*\(\?\)\s*$/', '', $summary) : null,
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
