<?php

namespace App\Services\Calendar;

use App\Domain\Calendar\AvailabilityResult;
use App\Domain\Calendar\AvailabilitySlot;
use App\Domain\Calendar\ParsedEvent;
use Carbon\CarbonImmutable;

/**
 * Computes the final free/highlighted/unavailable/sleep result (§5.1),
 * ported from the source app's own AvailabilityController +
 * AvailabilityService to match its API contract exactly rather than our
 * own earlier single-flat-list design: the four
 * output arrays are computed mostly independently and can legitimately
 * overlap (an event that's both busy and highlighted appears in both
 * `unavailable` and `highlighted`) — see AvailabilityResult's doc comment
 * for why that's intentional, not a bug to resolve here.
 *
 * Every rule here has a matching test in
 * tests/Unit/Calendar/AvailabilityServiceTest.php.
 */
class AvailabilityService
{
    public function __construct(
        private readonly HighlightMatcher $matcher,
        private readonly ActivityExtractor $activityExtractor,
    ) {}

    /**
     * @param  ParsedEvent[]  $events
     * @param  array<int, array{wake: ?string, sleep: ?string}>  $weeklyAvailability  Keyed 0 (Sun) .. 6 (Sat).
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}[]  $sleepExceptions  Date-only ranges; suppress the default sleep block.
     * @param  string[]  $highlightWords
     */
    public function compute(
        array $events,
        array $weeklyAvailability,
        array $sleepExceptions,
        ?string $dndEventPattern,
        ?string $napEventPattern,
        array $highlightWords,
        bool $bypassDnd,
        CarbonImmutable $rangeStart,
        CarbonImmutable $rangeEnd,
        ?string $highlightClausePattern = null,
        ?string $activityClausePattern = null,
        bool $showActivity = true,
        ?string $workEventPattern = null,
        ?string $highlightSplitPattern = null,
        ?string $schoolEventPattern = null,
    ): AvailabilityResult {
        $napIntervals = [];
        $busyIntervals = [];
        $unavailable = [];
        $work = [];
        $school = [];
        $highlighted = [];

        foreach ($events as $event) {
            if ($event->matchesEventNamePattern($dndEventPattern) && ! $bypassDnd) {
                continue;
            }

            $busyIntervals[] = ['start' => $event->start, 'end' => $event->end];
            $unavailable[] = ['start' => $event->start, 'end' => $event->end, 'tentativeStart' => $event->tentativeStart, 'tentativeEnd' => $event->tentativeEnd];

            if ($event->matchesEventNamePattern($napEventPattern)) {
                $napIntervals[] = ['start' => $event->start, 'end' => $event->end];
            }

            // Still counted as ordinary busy time above (kept in
            // $unavailable/$busyIntervals) — this just additionally tags the
            // same span as "work" so the calendar can render it as its own
            // category, the same double-bookkeeping AvailabilityResult's own
            // doc comment already describes for `highlighted`.
            if ($event->matchesEventNamePattern($workEventPattern)) {
                $work[] = ['start' => $event->start, 'end' => $event->end, 'tentativeStart' => $event->tentativeStart, 'tentativeEnd' => $event->tentativeEnd];
            }

            // Same double-bookkeeping as work above.
            if ($event->matchesEventNamePattern($schoolEventPattern)) {
                $school[] = ['start' => $event->start, 'end' => $event->end, 'tentativeStart' => $event->tentativeStart, 'tentativeEnd' => $event->tentativeEnd];
            }

            $highlightMatch = $this->matcher->match($event, $highlightWords, $highlightClausePattern, $highlightSplitPattern);

            if ($highlightMatch !== null) {
                $activity = ($showActivity && $event->summary !== null)
                    ? $this->activityExtractor->extract($event->summary, $activityClausePattern)
                    : null;

                $highlighted[] = new AvailabilitySlot(
                    start: $event->start,
                    end: $event->end,
                    tentativeStart: $event->tentativeStart,
                    tentativeEnd: $event->tentativeEnd,
                    activity: $activity,
                    visiting: $highlightMatch->visiting,
                    hosting: $highlightMatch->hosting,
                    highlightWords: $highlightMatch->words,
                );
            }
        }

        $sleepIntervals = $this->mergeIntervals([
            ...$this->computeSleepBlocks($weeklyAvailability, $sleepExceptions, $rangeStart, $rangeEnd),
            ...$napIntervals,
        ]);

        $unavailable = $this->subtractSleepFromEvents($unavailable, $sleepIntervals);
        $unavailable = $this->mergeEventSegments($unavailable);

        $work = $this->subtractSleepFromEvents($work, $sleepIntervals);
        $work = $this->mergeEventSegments($work);

        $school = $this->subtractSleepFromEvents($school, $sleepIntervals);
        $school = $this->mergeEventSegments($school);

        $free = $this->computeFreeRanges($weeklyAvailability, $busyIntervals, $rangeStart, $rangeEnd);

        return new AvailabilityResult(
            free: array_map(fn ($s) => new AvailabilitySlot($s['start'], $s['end']), $free),
            highlighted: $highlighted,
            unavailable: array_map(fn ($s) => new AvailabilitySlot($s['start'], $s['end'], tentativeStart: $s['tentativeStart'], tentativeEnd: $s['tentativeEnd']), $unavailable),
            work: array_map(fn ($s) => new AvailabilitySlot($s['start'], $s['end'], tentativeStart: $s['tentativeStart'], tentativeEnd: $s['tentativeEnd']), $work),
            school: array_map(fn ($s) => new AvailabilitySlot($s['start'], $s['end'], tentativeStart: $s['tentativeStart'], tentativeEnd: $s['tentativeEnd']), $school),
            sleep: array_map(fn ($s) => new AvailabilitySlot($s['start'], $s['end']), $sleepIntervals),
        );
    }

    /**
     * Per-day: the day's own wake→sleep window (or the whole day, if
     * either is unset — matching Settings.vue's "leave both blank for no
     * default sleep block that day"), clipped to the requested range, with
     * $busyIntervals subtracted out. Deliberately ignores $sleepExceptions
     * (unlike computeSleepBlocks) — matching the source app's own
     * inconsistency here rather than "fixing" it, since this is a verbatim
     * port (PLAN.md's Free-viewer port note).
     *
     * @param  array<int, array{wake: ?string, sleep: ?string}>  $weeklyAvailability
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}[]  $busyIntervals
     * @return array{start: CarbonImmutable, end: CarbonImmutable}[]
     */
    private function computeFreeRanges(array $weeklyAvailability, array $busyIntervals, CarbonImmutable $rangeStart, CarbonImmutable $rangeEnd): array
    {
        $free = [];
        $day = $rangeStart->startOfDay();

        while ($day->lte($rangeEnd)) {
            $window = $this->dayWindow($day, $weeklyAvailability);

            if ($window === null) {
                $day = $day->addDay();

                continue;
            }

            [$windowStart, $windowEnd] = $window;
            $windowStart = $windowStart->max($rangeStart);
            $windowEnd = $windowEnd->min($rangeEnd);

            if ($windowStart->lt($windowEnd)) {
                $free = [...$free, ...$this->subtractIntervals([['start' => $windowStart, 'end' => $windowEnd]], $busyIntervals)];
            }

            $day = $day->addDay();
        }

        return $free;
    }

    /**
     * Awake windows per day (respecting $sleepExceptions — a whole day
     * becomes fully awake), merged, then inverted across the requested
     * range to get the actual sleep gaps.
     *
     * @param  array<int, array{wake: ?string, sleep: ?string}>  $weeklyAvailability
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}[]  $sleepExceptions
     * @return array{start: CarbonImmutable, end: CarbonImmutable}[]
     */
    private function computeSleepBlocks(array $weeklyAvailability, array $sleepExceptions, CarbonImmutable $rangeStart, CarbonImmutable $rangeEnd): array
    {
        $awakeWindows = [];
        $day = $rangeStart->startOfDay();

        while ($day->lte($rangeEnd)) {
            if ($this->isSuppressedByException($day, $sleepExceptions)) {
                $windowStart = $day->startOfDay()->max($rangeStart);
                $windowEnd = $day->addDay()->startOfDay()->min($rangeEnd);

                if ($windowStart->lt($windowEnd)) {
                    $awakeWindows[] = ['start' => $windowStart, 'end' => $windowEnd];
                }

                $day = $day->addDay();

                continue;
            }

            $window = $this->dayWindow($day, $weeklyAvailability);

            if ($window !== null) {
                [$windowStart, $windowEnd] = $window;
                $windowStart = $windowStart->max($rangeStart);
                $windowEnd = $windowEnd->min($rangeEnd);

                if ($windowStart->lt($windowEnd)) {
                    $awakeWindows[] = ['start' => $windowStart, 'end' => $windowEnd];
                }
            }

            $day = $day->addDay();
        }

        $mergedAwake = $this->mergeIntervals($awakeWindows);

        $sleepBlocks = [];
        $cursor = $rangeStart;

        foreach ($mergedAwake as $window) {
            if ($window['start']->gt($cursor)) {
                $sleepBlocks[] = ['start' => $cursor, 'end' => $window['start']];
            }
            if ($window['end']->gt($cursor)) {
                $cursor = $window['end'];
            }
        }

        if ($cursor->lt($rangeEnd)) {
            $sleepBlocks[] = ['start' => $cursor, 'end' => $rangeEnd];
        }

        return $sleepBlocks;
    }

    /**
     * A day's configured wake→sleep window, or null if there's no
     * configuration for that weekday at all. Blank wake+sleep both means
     * "awake all day" (the whole day, not "no window") per Settings.vue's
     * "leave both blank for no default sleep block that day".
     *
     * @param  array<int, array{wake: ?string, sleep: ?string}>  $weeklyAvailability
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function dayWindow(CarbonImmutable $day, array $weeklyAvailability): array
    {
        $config = $weeklyAvailability[(int) $day->format('w')] ?? null;

        $windowStart = ! empty($config['wake'])
            ? CarbonImmutable::parse($day->toDateString().' '.$config['wake'], $day->getTimezone())
            : $day->startOfDay();

        if (! empty($config['sleep'])) {
            $windowEnd = CarbonImmutable::parse($day->toDateString().' '.$config['sleep'], $day->getTimezone());
            if ($windowEnd->lte($windowStart)) {
                $windowEnd = $windowEnd->addDay();
            }
        } else {
            // Next midnight, not this day's endOfDay() (23:59:59.999999) —
            // the latter leaves a 1-microsecond gap against the following
            // day's startOfDay() that computeSleepBlocks' inversion picks
            // up as a degenerate "sleep" entry for every day boundary in
            // the range, even with no wake/sleep configured at all. User-
            // reported: showed up as spurious sleep records with nothing
            // configured. Exact midnight-to-midnight closes the gap so
            // consecutive blank days merge into one continuous awake
            // window with zero sleep artifacts.
            $windowEnd = $day->addDay()->startOfDay();
        }

        return [$windowStart, $windowEnd];
    }

    /** @param  array{start: CarbonImmutable, end: CarbonImmutable}[]  $sleepExceptions */
    private function isSuppressedByException(CarbonImmutable $date, array $sleepExceptions): bool
    {
        foreach ($sleepExceptions as $exception) {
            if ($date->toDateString() >= $exception['start']->toDateString()
                && $date->toDateString() <= $exception['end']->toDateString()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sorts by start and merges any that overlap or directly touch into one.
     *
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}[]  $intervals
     * @return array{start: CarbonImmutable, end: CarbonImmutable}[]
     */
    private function mergeIntervals(array $intervals): array
    {
        usort($intervals, fn ($a, $b) => $a['start']->getTimestamp() <=> $b['start']->getTimestamp());

        $merged = [];

        foreach ($intervals as $interval) {
            if (! empty($merged) && $interval['start']->lte($merged[count($merged) - 1]['end'])) {
                $last = &$merged[count($merged) - 1];
                if ($interval['end']->gt($last['end'])) {
                    $last['end'] = $interval['end'];
                }
                unset($last);
            } else {
                $merged[] = $interval;
            }
        }

        return $merged;
    }

    /**
     * Removes the portions of $slots that overlap any interval in $subtract.
     *
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}[]  $slots
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}[]  $subtract
     * @return array{start: CarbonImmutable, end: CarbonImmutable}[]
     */
    private function subtractIntervals(array $slots, array $subtract): array
    {
        foreach ($subtract as $interval) {
            $es = $interval['start'];
            $ee = $interval['end'];
            $newSlots = [];

            foreach ($slots as $slot) {
                if ($ee->lte($slot['start']) || $es->gte($slot['end'])) {
                    $newSlots[] = $slot;
                } elseif ($es->lte($slot['start']) && $ee->gte($slot['end'])) {
                    // interval fully covers slot — removed
                } elseif ($es->lte($slot['start'])) {
                    $newSlots[] = ['start' => $ee, 'end' => $slot['end']];
                } elseif ($ee->gte($slot['end'])) {
                    $newSlots[] = ['start' => $slot['start'], 'end' => $es];
                } else {
                    $newSlots[] = ['start' => $slot['start'], 'end' => $es];
                    $newSlots[] = ['start' => $ee, 'end' => $slot['end']];
                }
            }

            $slots = $newSlots;
        }

        return $slots;
    }

    /**
     * Clips each event against $sleepBlocks so sleep takes precedence over
     * busy time: events fully within a sleep block are dropped, and events
     * straddling one are split around it. Each resulting segment keeps its
     * originating event's own tentativeStart/tentativeEnd flags.
     *
     * @param  array{start: CarbonImmutable, end: CarbonImmutable, tentativeStart: bool, tentativeEnd: bool}[]  $events
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}[]  $sleepBlocks
     * @return array{start: CarbonImmutable, end: CarbonImmutable, tentativeStart: bool, tentativeEnd: bool}[]
     */
    private function subtractSleepFromEvents(array $events, array $sleepBlocks): array
    {
        $segments = [];

        foreach ($events as $event) {
            $remaining = $this->subtractIntervals([['start' => $event['start'], 'end' => $event['end']]], $sleepBlocks);

            foreach ($remaining as $slot) {
                $segments[] = ['start' => $slot['start'], 'end' => $slot['end'], 'tentativeStart' => $event['tentativeStart'], 'tentativeEnd' => $event['tentativeEnd']];
            }
        }

        usort($segments, fn ($a, $b) => $a['start']->getTimestamp() <=> $b['start']->getTimestamp());

        return $segments;
    }

    /**
     * Merges directly adjacent/overlapping segments of the same
     * tentativeStart/tentativeEnd pair into one, applying a 3-tier
     * precedence cascade across the resulting four buckets rather than
     * reporting redundant overlapping ranges:
     *
     * 1. Fully-tentative (both edges unknown — "might not happen at all")
     *    has the highest precedence: its own exact span is carved out of
     *    every other bucket it overlaps.
     * 2. Confirmed (neither edge unknown) is carved by fully-tentative,
     *    but itself takes precedence over the two open-edged buckets below
     *    — a confirmed event's span always wins an overlap against one
     *    with a merely fuzzy boundary.
     * 3. Open-start-only and open-end-only (exactly one edge unknown) sit
     *    at the bottom: carved by both of the above, but never carved by
     *    or carving each other — there's no ordering between the two, so
     *    they're left independent where they overlap.
     *
     * @param  array{start: CarbonImmutable, end: CarbonImmutable, tentativeStart: bool, tentativeEnd: bool}[]  $segments
     * @return array{start: CarbonImmutable, end: CarbonImmutable, tentativeStart: bool, tentativeEnd: bool}[]
     */
    private function mergeEventSegments(array $segments): array
    {
        $fullyTentative = array_values(array_filter($segments, fn ($s) => $s['tentativeStart'] && $s['tentativeEnd']));
        $confirmed = array_values(array_filter($segments, fn ($s) => ! $s['tentativeStart'] && ! $s['tentativeEnd']));
        $openStart = array_values(array_filter($segments, fn ($s) => $s['tentativeStart'] && ! $s['tentativeEnd']));
        $openEnd = array_values(array_filter($segments, fn ($s) => ! $s['tentativeStart'] && $s['tentativeEnd']));

        $mergedFullyTentative = $this->mergeIntervals($fullyTentative);
        $mergedConfirmed = $this->mergeIntervals($this->carveOut($confirmed, $mergedFullyTentative));
        $mergedOpenStart = $this->mergeIntervals($this->carveOut($this->carveOut($openStart, $mergedFullyTentative), $mergedConfirmed));
        $mergedOpenEnd = $this->mergeIntervals($this->carveOut($this->carveOut($openEnd, $mergedFullyTentative), $mergedConfirmed));

        $merged = [
            ...array_map(fn ($s) => ['start' => $s['start'], 'end' => $s['end'], 'tentativeStart' => true, 'tentativeEnd' => true], $mergedFullyTentative),
            ...array_map(fn ($s) => ['start' => $s['start'], 'end' => $s['end'], 'tentativeStart' => false, 'tentativeEnd' => false], $mergedConfirmed),
            ...array_map(fn ($s) => ['start' => $s['start'], 'end' => $s['end'], 'tentativeStart' => true, 'tentativeEnd' => false], $mergedOpenStart),
            ...array_map(fn ($s) => ['start' => $s['start'], 'end' => $s['end'], 'tentativeStart' => false, 'tentativeEnd' => true], $mergedOpenEnd),
        ];
        usort($merged, fn ($a, $b) => $a['start']->getTimestamp() <=> $b['start']->getTimestamp());

        return $merged;
    }

    /**
     * Removes every portion of $events that overlaps $carveOut — each
     * surviving fragment keeps only its start/end (any other keys on the
     * input, e.g. stale tentative flags, are intentionally dropped since
     * the caller reattaches the correct bucket-wide flags afterward).
     *
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}[]  $events
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}[]  $carveOut
     * @return array{start: CarbonImmutable, end: CarbonImmutable}[]
     */
    private function carveOut(array $events, array $carveOut): array
    {
        $result = [];

        foreach ($events as $event) {
            $remaining = $this->subtractIntervals([['start' => $event['start'], 'end' => $event['end']]], $carveOut);
            foreach ($remaining as $slot) {
                $result[] = ['start' => $slot['start'], 'end' => $slot['end']];
            }
        }

        return $result;
    }
}
