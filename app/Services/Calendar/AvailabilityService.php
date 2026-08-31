<?php

namespace App\Services\Calendar;

use App\Domain\Calendar\AvailabilityResult;
use App\Domain\Calendar\AvailabilitySlot;
use App\Domain\Calendar\ManualTag;
use App\Domain\Calendar\ParsedEvent;
use Carbon\CarbonImmutable;

/**
 * Computes the final free/highlighted/unavailable/sleep result (§5.1),
 * ported from the source app's own AvailabilityController +
 * AvailabilityService (the source app) to match its API contract
 * exactly rather than our own earlier single-flat-list design: the four
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
     * @param  ManualTag[]  $manualTags
     */
    public function compute(
        array $events,
        array $weeklyAvailability,
        array $sleepExceptions,
        ?string $dndEventName,
        ?string $napEventName,
        array $highlightWords,
        array $manualTags,
        bool $bypassDnd,
        CarbonImmutable $rangeStart,
        CarbonImmutable $rangeEnd,
        ?string $highlightClausePattern = null,
        ?string $activityClausePattern = null,
        bool $showActivity = true,
    ): AvailabilityResult {
        $napIntervals = [];
        $busyIntervals = [];
        $unavailable = [];
        $highlighted = [];

        foreach ($events as $event) {
            if ($event->matchesEventNamePattern($dndEventName) && ! $bypassDnd) {
                continue;
            }

            $busyIntervals[] = ['start' => $event->start, 'end' => $event->end];
            $unavailable[] = ['start' => $event->start, 'end' => $event->end, 'tentative' => $event->isTentative];

            if ($event->matchesEventNamePattern($napEventName)) {
                $napIntervals[] = ['start' => $event->start, 'end' => $event->end];
            }

            $highlightMatch = $this->matcher->match($event, $highlightWords, $manualTags, $highlightClausePattern);

            if ($highlightMatch !== null) {
                $activity = ($showActivity && $event->summary !== null)
                    ? $this->activityExtractor->extract($event->summary, $activityClausePattern)
                    : null;

                $highlighted[] = new AvailabilitySlot(
                    start: $event->start,
                    end: $event->end,
                    tentative: $event->isTentative,
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

        $free = $this->computeFreeRanges($weeklyAvailability, $busyIntervals, $rangeStart, $rangeEnd);

        return new AvailabilityResult(
            free: array_map(fn ($s) => new AvailabilitySlot($s['start'], $s['end']), $free),
            highlighted: $highlighted,
            unavailable: array_map(fn ($s) => new AvailabilitySlot($s['start'], $s['end'], tentative: $s['tentative']), $unavailable),
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
            ? CarbonImmutable::parse($day->toDateString().' '.$config['wake'])
            : $day->startOfDay();

        if (! empty($config['sleep'])) {
            $windowEnd = CarbonImmutable::parse($day->toDateString().' '.$config['sleep']);
            if ($windowEnd->lte($windowStart)) {
                $windowEnd = $windowEnd->addDay();
            }
        } else {
            $windowEnd = $day->endOfDay();
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
     * originating event's own tentative flag.
     *
     * @param  array{start: CarbonImmutable, end: CarbonImmutable, tentative: bool}[]  $events
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}[]  $sleepBlocks
     * @return array{start: CarbonImmutable, end: CarbonImmutable, tentative: bool}[]
     */
    private function subtractSleepFromEvents(array $events, array $sleepBlocks): array
    {
        $segments = [];

        foreach ($events as $event) {
            $remaining = $this->subtractIntervals([['start' => $event['start'], 'end' => $event['end']]], $sleepBlocks);

            foreach ($remaining as $slot) {
                $segments[] = ['start' => $slot['start'], 'end' => $slot['end'], 'tentative' => $event['tentative']];
            }
        }

        usort($segments, fn ($a, $b) => $a['start']->getTimestamp() <=> $b['start']->getTimestamp());

        return $segments;
    }

    /**
     * Merges directly adjacent/overlapping segments of the same tentative-
     * ness into one. A tentative segment is carved out of any confirmed
     * segment it overlaps (its own exact span takes precedence) rather
     * than the two being reported as redundant overlapping ranges — so a
     * tentative segment never merges into a confirmed one or vice versa.
     *
     * @param  array{start: CarbonImmutable, end: CarbonImmutable, tentative: bool}[]  $segments
     * @return array{start: CarbonImmutable, end: CarbonImmutable, tentative: bool}[]
     */
    private function mergeEventSegments(array $segments): array
    {
        $tentative = array_values(array_filter($segments, fn ($s) => $s['tentative']));
        $confirmed = array_values(array_filter($segments, fn ($s) => ! $s['tentative']));

        $mergedTentative = array_map(
            fn ($s) => ['start' => $s['start'], 'end' => $s['end'], 'tentative' => true],
            $this->mergeIntervals($tentative),
        );

        $carvedConfirmed = [];
        foreach ($confirmed as $event) {
            $remaining = $this->subtractIntervals([['start' => $event['start'], 'end' => $event['end']]], $mergedTentative);
            foreach ($remaining as $slot) {
                $carvedConfirmed[] = ['start' => $slot['start'], 'end' => $slot['end'], 'tentative' => false];
            }
        }
        $mergedConfirmed = array_map(
            fn ($s) => ['start' => $s['start'], 'end' => $s['end'], 'tentative' => false],
            $this->mergeIntervals($carvedConfirmed),
        );

        $merged = [...$mergedTentative, ...$mergedConfirmed];
        usort($merged, fn ($a, $b) => $a['start']->getTimestamp() <=> $b['start']->getTimestamp());

        return $merged;
    }
}
