<?php

namespace App\Services\Calendar;

use App\Domain\Calendar\RawCalendarItem;
use App\Support\Regex;
use Carbon\CarbonImmutable;
use Sabre\VObject\Component;
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
     * its own trailing $. Owner-customizable via users.tentative_pattern —
     * blank genuinely turns tentative-title detection off entirely (same
     * "blank is a real off state" convention as dnd/nap/work/school), it is
     * NOT silently substituted as a fallback default the way it used to be;
     * this constant is only ever surfaced to the owner as a *suggested*
     * starting value (SettingsController's own doc comment), never applied
     * behind their back. A plain literal like "(?)" still works as-is;
     * owners whose own convention differs (e.g. a trailing "[tentative]")
     * can set it explicitly the same way as any other pattern here.
     */
    public const DEFAULT_TENTATIVE_TITLE_PATTERN = '\(\?\)\s*$';

    /**
     * Confirmed event, end time unknown/open-ended — e.g. "Dinner (-?)".
     * Sets only $tentativeEnd, unlike DEFAULT_TENTATIVE_TITLE_PATTERN which
     * sets both. Chosen so it never collides with the "(?)" pattern above:
     * that one requires "(", "?", ")" immediately consecutive at the end,
     * which "(-?)"'s trailing "-?)" never produces. Same suggested-only,
     * never-auto-applied treatment as DEFAULT_TENTATIVE_TITLE_PATTERN.
     */
    public const DEFAULT_OPEN_END_TITLE_PATTERN = '\(-\?\)\s*$';

    /**
     * Confirmed event, start time unknown/open-ended — e.g. "Dinner (?-)".
     * Sets only $tentativeStart. Same non-collision reasoning as
     * DEFAULT_OPEN_END_TITLE_PATTERN, and same suggested-only treatment.
     */
    public const DEFAULT_OPEN_START_TITLE_PATTERN = '\(\?-\)\s*$';

    /**
     * @param  'full_detail'|'free_busy_only'  $parsingMode  Gates only the
     *                                                       three title-*regex* signals below (tentative/open-end/open-start
     *                                                       suffixes) — `free_busy_only` skips evaluating and stripping them
     *                                                       entirely, since a free-busy-only feed's SUMMARY (if present at all)
     *                                                       is a fake generic placeholder like "Busy", not real title text. Does
     *                                                       NOT gate the structured ICS STATUS:TENTATIVE / VFREEBUSY
     *                                                       FBTYPE=BUSY-TENTATIVE signals, which stay honored either way.
     * @return RawCalendarItem[]
     */
    public function parse(
        string $icsBody,
        CarbonImmutable $rangeStart,
        CarbonImmutable $rangeEnd,
        ?string $tentativeTitlePattern = null,
        ?string $openEndTitlePattern = null,
        ?string $openStartTitlePattern = null,
        string $parsingMode = 'full_detail',
    ): array {
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

        $applyTitlePatterns = $parsingMode !== 'free_busy_only';

        foreach ($expandedCalendar->select('VEVENT') as $vevent) {
            $item = $this->parseVEvent($vevent, $tentativeTitlePattern, $openEndTitlePattern, $openStartTitlePattern, $applyTitlePatterns);

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

    private function parseVEvent(
        Component $vevent,
        ?string $tentativeTitlePattern,
        ?string $openEndTitlePattern,
        ?string $openStartTitlePattern,
        bool $applyTitlePatterns,
    ): ?RawCalendarItem {
        if (! isset($vevent->DTSTART)) {
            return null;
        }

        $start = CarbonImmutable::instance($vevent->DTSTART->getDateTime());
        $end = isset($vevent->DTEND)
            ? CarbonImmutable::instance($vevent->DTEND->getDateTime())
            : $start->addHour();

        $summary = isset($vevent->SUMMARY) ? (string) $vevent->SUMMARY : null;
        $isTentativeStatus = isset($vevent->STATUS) && strtoupper((string) $vevent->STATUS) === 'TENTATIVE';

        $isTentativeTitle = false;
        $isOpenEndTitle = false;
        $isOpenStartTitle = false;

        if ($applyTitlePatterns) {
            // Each of the three patterns is checked and stripped
            // independently (against the progressively-cleaned summary),
            // then OR'd into the two directional flags. The three defaults
            // can never collide with each other (see the
            // DEFAULT_*_TITLE_PATTERN doc comments), but a custom owner
            // pattern in principle could match more than one — stripping
            // sequentially keeps that safe either way.
            [$isTentativeTitle, $summary] = $this->matchAndStrip($tentativeTitlePattern, $summary);
            [$isOpenEndTitle, $summary] = $this->matchAndStrip($openEndTitlePattern, $summary);
            [$isOpenStartTitle, $summary] = $this->matchAndStrip($openStartTitlePattern, $summary);
        }

        return new RawCalendarItem(
            uid: isset($vevent->UID) ? (string) $vevent->UID : bin2hex(random_bytes(8)),
            start: $start,
            end: $end,
            componentType: 'VEVENT',
            summary: $summary,
            description: isset($vevent->DESCRIPTION) ? (string) $vevent->DESCRIPTION : null,
            location: isset($vevent->LOCATION) ? (string) $vevent->LOCATION : null,
            tentativeStart: $isTentativeStatus || $isTentativeTitle || $isOpenStartTitle,
            tentativeEnd: $isTentativeStatus || $isTentativeTitle || $isOpenEndTitle,
        );
    }

    /**
     * Matches $pattern (a delimiter-less regex fragment) against the
     * trailing end of $summary, case-insensitively, and strips it out if
     * found — so it never leaks into downstream DND/nap/highlight/activity
     * matching or gets shown anywhere. \x01 delimiter, same reasoning as
     * ParsedEvent::matchesEventNamePattern: lets an owner's pattern contain
     * any printable character freely. An invalid pattern fails closed (no
     * match) rather than throwing.
     *
     * A null/blank $pattern means the feature is genuinely off (see the
     * DEFAULT_*_TITLE_PATTERN doc comments) — this must be checked before
     * ever building $delimitedPattern, not left to fail closed the way an
     * actually-invalid pattern does: an *empty* regex body between two
     * `\x01` delimiters is syntactically valid PCRE that trivially matches
     * a zero-length string at the current position, so it would otherwise
     * report every summary as "matched" instead of skipping the check.
     *
     * @return array{0: bool, 1: ?string} [matched, cleaned summary]
     */
    private function matchAndStrip(?string $pattern, ?string $summary): array
    {
        if ($pattern === null || $pattern === '' || $summary === null) {
            return [false, $summary];
        }

        $delimitedPattern = "\x01".$pattern."\x01iu";
        $matched = Regex::tryMatch($delimitedPattern, trim($summary)) !== null;

        if (! $matched) {
            return [false, $summary];
        }

        $cleaned = preg_replace("\x01\\s*".ltrim($delimitedPattern, "\x01"), '', $summary);

        return [true, $cleaned];
    }

    /** @return RawCalendarItem[] */
    private function parseVFreeBusy(Component $vfreebusy): array
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
                    tentativeStart: $fbType === 'BUSY-TENTATIVE',
                    tentativeEnd: $fbType === 'BUSY-TENTATIVE',
                );
            }
        }

        return $items;
    }
}
