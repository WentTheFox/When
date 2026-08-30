<?php

namespace App\Services\Calendar;

use App\Domain\Calendar\AvailabilitySlot;
use App\Domain\Calendar\ManualTag;
use App\Domain\Calendar\ParsedEvent;
use App\Domain\Calendar\SlotType;
use Carbon\CarbonImmutable;

/**
 * Computes the final free/busy/sleep/tentative/highlighted result (§5.1).
 * Every rule here is load-bearing and has a matching test in
 * tests/Unit/Calendar/AvailabilityServiceTest.php — see PLAN.md §5.1, which
 * treats each bullet below as a required test case, not just a description.
 */
class AvailabilityService
{
    public function __construct(private readonly HighlightMatcher $matcher) {}

    /**
     * @param  ParsedEvent[]  $events
     * @param  array<int, array{wake: ?string, sleep: ?string}>  $weeklyAvailability  Keyed 0 (Sun) .. 6 (Sat).
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}[]  $sleepExceptions  Date-only ranges; suppress the default sleep block.
     * @param  string[]  $highlightWords
     * @param  ManualTag[]  $manualTags
     * @return AvailabilitySlot[]
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
    ): array {
        $intervals = [];

        foreach ($this->buildDefaultSleepIntervals($weeklyAvailability, $sleepExceptions, $rangeStart, $rangeEnd) as $interval) {
            $intervals[] = $interval;
        }

        $nappedUids = [];

        foreach ($events as $event) {
            if ($event->matchesEventNamePattern($napEventName)) {
                $intervals[] = ['type' => SlotType::Sleep, 'start' => $event->start, 'end' => $event->end, 'word' => null];
                $nappedUids[$event->uid] = true;
            }
        }

        foreach ($events as $event) {
            if (isset($nappedUids[$event->uid])) {
                continue;
            }

            if ($event->matchesEventNamePattern($dndEventName) && ! $bypassDnd) {
                continue;
            }

            if ($event->isTentative) {
                $intervals[] = ['type' => SlotType::Tentative, 'start' => $event->start, 'end' => $event->end, 'word' => null];
                continue;
            }

            $highlightWord = $this->matcher->match($event, $highlightWords, $manualTags, $highlightClausePattern);

            if ($highlightWord !== null) {
                $intervals[] = ['type' => SlotType::Highlighted, 'start' => $event->start, 'end' => $event->end, 'word' => $highlightWord];
                continue;
            }

            $intervals[] = ['type' => SlotType::Busy, 'start' => $event->start, 'end' => $event->end, 'word' => null];
        }

        $resolved = $this->resolveOverlapsByPrecedence($intervals, $rangeStart, $rangeEnd);

        return $this->mergeAdjacent($resolved);
    }

    /**
     * @param  array<int, array{wake: ?string, sleep: ?string}>  $weeklyAvailability
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}[]  $sleepExceptions
     * @return array{type: SlotType, start: CarbonImmutable, end: CarbonImmutable, word: null}[]
     */
    private function buildDefaultSleepIntervals(
        array $weeklyAvailability,
        array $sleepExceptions,
        CarbonImmutable $rangeStart,
        CarbonImmutable $rangeEnd,
    ): array {
        $intervals = [];
        $date = $rangeStart->startOfDay()->subDay(); // a sleep block starting the day before can still extend into range

        while ($date < $rangeEnd) {
            if (! $this->isSuppressedByException($date, $sleepExceptions)) {
                $weekday = (int) $date->format('w');
                $config = $weeklyAvailability[$weekday] ?? null;

                if ($config !== null && ! empty($config['sleep']) && ! empty($config['wake'])) {
                    $sleepStart = CarbonImmutable::parse($date->toDateString().' '.$config['sleep']);
                    $sleepEnd = CarbonImmutable::parse($date->addDay()->toDateString().' '.$config['wake']);

                    if ($sleepEnd <= $sleepStart) {
                        $sleepEnd = $sleepEnd->addDay();
                    }

                    if ($sleepEnd > $rangeStart && $sleepStart < $rangeEnd) {
                        $intervals[] = ['type' => SlotType::Sleep, 'start' => $sleepStart, 'end' => $sleepEnd, 'word' => null];
                    }
                }
            }

            $date = $date->addDay();
        }

        return $intervals;
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
     * Sweep-line: at every point covered by more than one interval, only
     * the highest-precedence type survives (§5.1: sleep always wins over a
     * conflicting event).
     *
     * @param  array{type: SlotType, start: CarbonImmutable, end: CarbonImmutable, word: ?string}[]  $intervals
     * @return array{type: SlotType, start: CarbonImmutable, end: CarbonImmutable, word: ?string}[]
     */
    private function resolveOverlapsByPrecedence(array $intervals, CarbonImmutable $rangeStart, CarbonImmutable $rangeEnd): array
    {
        $boundaries = [];

        foreach ($intervals as $interval) {
            $start = $interval['start']->max($rangeStart);
            $end = $interval['end']->min($rangeEnd);

            if ($start >= $end) {
                continue;
            }

            $boundaries[] = $start->getTimestamp();
            $boundaries[] = $end->getTimestamp();
        }

        if (empty($boundaries)) {
            return [];
        }

        sort($boundaries);
        $boundaries = array_values(array_unique($boundaries));

        $resolved = [];

        for ($i = 0; $i < count($boundaries) - 1; $i++) {
            $segStart = $boundaries[$i];
            $segEnd = $boundaries[$i + 1];

            $winner = null;

            foreach ($intervals as $interval) {
                $start = $interval['start']->max($rangeStart)->getTimestamp();
                $end = $interval['end']->min($rangeEnd)->getTimestamp();

                if ($start <= $segStart && $end >= $segEnd) {
                    if ($winner === null || $interval['type']->precedence() > $winner['type']->precedence()) {
                        $winner = $interval;
                    }
                }
            }

            if ($winner !== null) {
                $resolved[] = [
                    'type' => $winner['type'],
                    'start' => CarbonImmutable::createFromTimestamp($segStart, $winner['start']->getTimezone()),
                    'end' => CarbonImmutable::createFromTimestamp($segEnd, $winner['start']->getTimezone()),
                    'word' => $winner['word'],
                ];
            }
        }

        return $resolved;
    }

    /**
     * Back-to-back / overlapping ranges of the same resolved type (and, for
     * highlighted, the same word) merge into one continuous block rather
     * than staying as adjacent fragments (§5.1).
     *
     * @param  array{type: SlotType, start: CarbonImmutable, end: CarbonImmutable, word: ?string}[]  $resolved
     * @return AvailabilitySlot[]
     */
    private function mergeAdjacent(array $resolved): array
    {
        if (empty($resolved)) {
            return [];
        }

        usort($resolved, fn ($a, $b) => $a['start']->getTimestamp() <=> $b['start']->getTimestamp());

        $merged = [$resolved[0]];

        for ($i = 1; $i < count($resolved); $i++) {
            $current = $resolved[$i];
            $last = &$merged[count($merged) - 1];

            $sameGroup = $last['type'] === $current['type'] && $last['word'] === $current['word'];
            $touching = $current['start']->getTimestamp() <= $last['end']->getTimestamp();

            if ($sameGroup && $touching) {
                if ($current['end'] > $last['end']) {
                    $last['end'] = $current['end'];
                }
            } else {
                $merged[] = $current;
            }
        }

        return array_map(
            fn ($slot) => new AvailabilitySlot($slot['type'], $slot['start'], $slot['end'], $slot['word']),
            $merged,
        );
    }
}
