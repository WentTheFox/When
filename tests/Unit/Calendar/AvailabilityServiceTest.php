<?php

namespace Tests\Unit\Calendar;

use App\Domain\Calendar\AvailabilityResult;
use App\Domain\Calendar\AvailabilitySlot;
use App\Domain\Calendar\ManualTag;
use App\Domain\Calendar\ParsedEvent;
use App\Services\Calendar\ActivityExtractor;
use App\Services\Calendar\AvailabilityService;
use App\Services\Calendar\HighlightMatcher;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class AvailabilityServiceTest extends TestCase
{
    private AvailabilityService $service;
    private CarbonImmutable $rangeStart;
    private CarbonImmutable $rangeEnd;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AvailabilityService(new HighlightMatcher(), new ActivityExtractor());
        // A Monday through the following Monday, UTC — kept fixed so weekday
        // math in the tests is predictable.
        $this->rangeStart = CarbonImmutable::parse('2026-06-01 00:00:00', 'UTC'); // Monday
        $this->rangeEnd = CarbonImmutable::parse('2026-06-08 00:00:00', 'UTC');
    }

    private function everyWeekday(string $wake, string $sleep): array
    {
        return array_fill(0, 7, ['wake' => $wake, 'sleep' => $sleep]);
    }

    private function event(
        string $uid,
        string $start,
        string $end,
        ?string $summary = null,
        ?string $description = null,
        ?string $location = null,
        bool $isFreeBusyOnly = false,
        bool $isTentative = false,
    ): ParsedEvent {
        return new ParsedEvent(
            uid: $uid,
            start: CarbonImmutable::parse($start, 'UTC'),
            end: CarbonImmutable::parse($end, 'UTC'),
            summary: $summary,
            description: $description,
            location: $location,
            isFreeBusyOnly: $isFreeBusyOnly,
            isTentative: $isTentative,
        );
    }

    private function compute(
        array $events = [],
        array $weeklyAvailability = [],
        array $sleepExceptions = [],
        ?string $dndEventName = null,
        ?string $napEventName = null,
        array $highlightWords = [],
        array $manualTags = [],
        bool $bypassDnd = false,
        bool $showActivity = true,
        ?string $activityClausePattern = null,
    ): AvailabilityResult {
        return $this->service->compute(
            $events,
            $weeklyAvailability ?: array_fill(0, 7, ['wake' => null, 'sleep' => null]),
            $sleepExceptions,
            $dndEventName,
            $napEventName,
            $highlightWords,
            $manualTags,
            $bypassDnd,
            $this->rangeStart,
            $this->rangeEnd,
            activityClausePattern: $activityClausePattern,
            showActivity: $showActivity,
        );
    }

    private function starts(array $slots): array
    {
        return array_map(fn (AvailabilitySlot $s) => $s->start->toIso8601String(), $slots);
    }

    public function test_default_sleep_blocks_are_generated_per_weekday_window(): void
    {
        $result = $this->compute(weeklyAvailability: $this->everyWeekday('07:00', '23:00'));

        $this->assertNotEmpty($result->sleep);

        // Monday night: 23:00 -> Tuesday 07:00. (The range also starts with a
        // sleep block clipped from the previous Sunday night, since a sleep
        // window can span into the range from just before it starts.)
        $mondayNight = array_values(array_filter(
            $result->sleep,
            fn (AvailabilitySlot $s) => $s->start->toIso8601String() === '2026-06-01T23:00:00+00:00',
        ));
        $this->assertCount(1, $mondayNight);
        $this->assertSame('2026-06-02T07:00:00+00:00', $mondayNight[0]->end->toIso8601String());
    }

    public function test_sleep_exception_suppresses_the_default_sleep_block_for_that_date(): void
    {
        $result = $this->compute(
            weeklyAvailability: $this->everyWeekday('07:00', '23:00'),
            sleepExceptions: [[
                'start' => CarbonImmutable::parse('2026-06-02', 'UTC'),
                'end' => CarbonImmutable::parse('2026-06-02', 'UTC'),
            ]],
        );

        foreach ($result->sleep as $slot) {
            // No sleep block should START on 2026-06-02 (the excepted date).
            $this->assertNotSame('2026-06-02', $slot->start->toDateString());
        }

        // The night before (Mon->Tue) and after (Wed->Thu) are unaffected.
        $starts = array_map(fn (AvailabilitySlot $s) => $s->start->toDateString(), $result->sleep);
        $this->assertContains('2026-06-01', $starts);
        $this->assertContains('2026-06-03', $starts);
    }

    public function test_nap_events_are_merged_into_sleep_and_removed_from_unavailable(): void
    {
        $result = $this->compute(
            events: [$this->event('nap-1', '2026-06-03 14:00', '2026-06-03 15:00', 'Afternoon Nap')],
            napEventName: 'Afternoon Nap',
        );

        $napSleep = array_values(array_filter(
            $result->sleep,
            fn (AvailabilitySlot $s) => $s->start->toIso8601String() === '2026-06-03T14:00:00+00:00',
        ));
        $this->assertCount(1, $napSleep);
        $this->assertSame('2026-06-03T15:00:00+00:00', $napSleep[0]->end->toIso8601String());

        // The nap event's own time is entirely subtracted out of unavailable
        // — it doesn't double up as both sleep and busy.
        $this->assertEmpty($result->unavailable);
    }

    public function test_dnd_events_are_excluded_entirely_by_default(): void
    {
        $result = $this->compute(
            events: [$this->event('dnd-1', '2026-06-03 09:00', '2026-06-03 10:00', 'Therapy')],
            dndEventName: 'Therapy',
        );

        $this->assertEmpty($result->unavailable);
        $this->assertEmpty($result->highlighted);
        // Excluded entirely means it doesn't even subtract from free time —
        // the whole day (no wake/sleep configured) is one free block.
        $wednesday = array_values(array_filter(
            $result->free,
            fn (AvailabilitySlot $s) => $s->start->toIso8601String() === '2026-06-03T00:00:00+00:00',
        ));
        $this->assertCount(1, $wednesday);
        $this->assertTrue($wednesday[0]->end->gte(CarbonImmutable::parse('2026-06-03 10:00:00', 'UTC')));
    }

    public function test_dnd_bypass_share_links_see_the_dnd_event(): void
    {
        $result = $this->compute(
            events: [$this->event('dnd-1', '2026-06-03 09:00', '2026-06-03 10:00', 'Therapy')],
            dndEventName: 'Therapy',
            bypassDnd: true,
        );

        $this->assertCount(1, $result->unavailable);
        $this->assertSame('2026-06-03T09:00:00+00:00', $result->unavailable[0]->start->toIso8601String());
    }

    public function test_sleep_takes_precedence_over_a_conflicting_unavailable_event(): void
    {
        $result = $this->compute(
            events: [$this->event('late-call', '2026-06-01 22:30', '2026-06-01 23:30', 'Late call')],
            weeklyAvailability: $this->everyWeekday('07:00', '23:00'),
        );

        // The overlapping 30 minutes (23:00-23:30) must be sleep, not
        // unavailable — and the portion before it (22:30-23:00) must survive.
        $this->assertCount(1, $result->unavailable);
        $this->assertSame('2026-06-01T22:30:00+00:00', $result->unavailable[0]->start->toIso8601String());
        $this->assertSame('2026-06-01T23:00:00+00:00', $result->unavailable[0]->end->toIso8601String());

        $overnightSleep = array_values(array_filter(
            $result->sleep,
            fn (AvailabilitySlot $s) => $s->start->toIso8601String() === '2026-06-01T23:00:00+00:00',
        ));
        $this->assertCount(1, $overnightSleep);
    }

    public function test_a_tentative_event_is_flagged_not_a_separate_category(): void
    {
        $result = $this->compute(
            events: [$this->event('maybe', '2026-06-03 15:00', '2026-06-03 16:00', 'Maybe lunch', isTentative: true)],
        );

        $this->assertCount(1, $result->unavailable);
        $this->assertTrue($result->unavailable[0]->tentative);
    }

    public function test_a_tentative_slot_is_carved_out_of_an_overlapping_confirmed_one(): void
    {
        $result = $this->compute(events: [
            $this->event('confirmed', '2026-06-03 09:00', '2026-06-03 12:00', 'Meeting'),
            $this->event('tentative', '2026-06-03 10:00', '2026-06-03 11:00', 'Maybe', isTentative: true),
        ]);

        $tentative = array_values(array_filter($result->unavailable, fn (AvailabilitySlot $s) => $s->tentative));
        $confirmed = array_values(array_filter($result->unavailable, fn (AvailabilitySlot $s) => ! $s->tentative));

        $this->assertCount(1, $tentative);
        $this->assertSame('2026-06-03T10:00:00+00:00', $tentative[0]->start->toIso8601String());
        $this->assertSame('2026-06-03T11:00:00+00:00', $tentative[0]->end->toIso8601String());

        // The confirmed event is split around the tentative carve-out, not merged with it.
        $confirmedRanges = array_map(
            fn (AvailabilitySlot $s) => [$s->start->toIso8601String(), $s->end->toIso8601String()],
            $confirmed,
        );
        $this->assertContains(['2026-06-03T09:00:00+00:00', '2026-06-03T10:00:00+00:00'], $confirmedRanges);
        $this->assertContains(['2026-06-03T11:00:00+00:00', '2026-06-03T12:00:00+00:00'], $confirmedRanges);
    }

    public function test_back_to_back_unavailable_events_merge_into_one_continuous_block(): void
    {
        $result = $this->compute(events: [
            $this->event('a', '2026-06-03 09:00', '2026-06-03 10:00', 'Meeting A'),
            $this->event('b', '2026-06-03 10:00', '2026-06-03 11:00', 'Meeting B'),
        ]);

        $this->assertCount(1, $result->unavailable);
        $this->assertSame('2026-06-03T09:00:00+00:00', $result->unavailable[0]->start->toIso8601String());
        $this->assertSame('2026-06-03T11:00:00+00:00', $result->unavailable[0]->end->toIso8601String());
    }

    public function test_overlapping_unavailable_events_merge_into_one_continuous_block(): void
    {
        $result = $this->compute(events: [
            $this->event('a', '2026-06-03 09:00', '2026-06-03 10:30', 'Meeting A'),
            $this->event('b', '2026-06-03 10:00', '2026-06-03 11:00', 'Meeting B'),
        ]);

        $this->assertCount(1, $result->unavailable);
        $this->assertSame('2026-06-03T09:00:00+00:00', $result->unavailable[0]->start->toIso8601String());
        $this->assertSame('2026-06-03T11:00:00+00:00', $result->unavailable[0]->end->toIso8601String());
    }

    public function test_full_detail_with_clause_matches_a_configured_highlight_word(): void
    {
        $result = $this->compute(
            events: [$this->event('c1', '2026-06-03 12:00', '2026-06-03 13:00', 'Coffee with Alice')],
            highlightWords: ['Alice', 'Bob'],
        );

        $this->assertCount(1, $result->highlighted);
        $this->assertSame(['Alice'], $result->highlighted[0]->highlightWords);
        // A highlighted event is also present in unavailable — the two arrays
        // legitimately overlap (AvailabilityResult's doc comment).
        $this->assertCount(1, $result->unavailable);
    }

    public function test_a_highlighted_event_carries_no_activity_when_no_pattern_is_configured(): void
    {
        // Activity extraction is a conservative opt-in (ActivityExtractor's
        // own doc comment) — an owner who's never touched this setting
        // shouldn't have event-title freetext shown to viewers.
        $result = $this->compute(
            events: [$this->event('c1', '2026-06-03 12:00', '2026-06-03 13:00', 'Coffee with Alice')],
            highlightWords: ['Alice'],
        );

        $this->assertNull($result->highlighted[0]->activity);
    }

    public function test_a_highlighted_event_carries_the_extracted_activity_once_a_pattern_is_configured(): void
    {
        $result = $this->compute(
            events: [$this->event('c1', '2026-06-03 12:00', '2026-06-03 13:00', 'Coffee with Alice')],
            highlightWords: ['Alice'],
            activityClausePattern: ActivityExtractor::DEFAULT_PATTERN,
        );

        $this->assertSame('Coffee', $result->highlighted[0]->activity);
    }

    public function test_show_activity_false_suppresses_the_activity_field(): void
    {
        $result = $this->compute(
            events: [$this->event('c1', '2026-06-03 12:00', '2026-06-03 13:00', 'Coffee with Alice')],
            highlightWords: ['Alice'],
            showActivity: false,
            activityClausePattern: ActivityExtractor::DEFAULT_PATTERN,
        );

        $this->assertSame(['Alice'], $result->highlighted[0]->highlightWords);
        $this->assertNull($result->highlighted[0]->activity);
    }

    public function test_a_host_prefixed_event_is_highlighted_with_visiting_set(): void
    {
        $result = $this->compute(
            events: [$this->event('c1', '2026-06-03 12:00', '2026-06-03 13:00', 'Host Alice')],
            highlightWords: ['Alice'],
        );

        $this->assertSame(['Alice'], $result->highlighted[0]->highlightWords);
        $this->assertTrue($result->highlighted[0]->visiting);
        $this->assertFalse($result->highlighted[0]->hosting);
    }

    public function test_full_detail_event_with_no_matching_clause_is_plain_unavailable(): void
    {
        $result = $this->compute(
            events: [$this->event('c1', '2026-06-03 12:00', '2026-06-03 13:00', 'Team standup')],
            highlightWords: ['Alice'],
        );

        $this->assertCount(1, $result->unavailable);
        $this->assertEmpty($result->highlighted);
    }

    public function test_free_busy_only_event_matches_via_location_not_title(): void
    {
        $result = $this->compute(
            events: [$this->event(
                'fb1', '2026-06-03 12:00', '2026-06-03 13:00',
                summary: 'Busy', location: 'Alice', isFreeBusyOnly: true,
            )],
            highlightWords: ['Alice'],
        );

        $this->assertCount(1, $result->highlighted);
        $this->assertSame(['Alice'], $result->highlighted[0]->highlightWords);
    }

    public function test_free_busy_only_event_falls_back_to_a_manual_time_block_tag(): void
    {
        // 2026-06-03 is a Wednesday.
        $result = $this->compute(
            events: [$this->event(
                'fb1', '2026-06-03 12:00', '2026-06-03 13:00',
                summary: 'Busy', isFreeBusyOnly: true,
            )],
            manualTags: [new ManualTag(word: 'Gym', weekday: 3, startTime: '12:00', endTime: '14:00')],
        );

        $this->assertCount(1, $result->highlighted);
        $this->assertSame(['Gym'], $result->highlighted[0]->highlightWords);
    }

    public function test_free_busy_only_event_with_no_signal_is_plain_unavailable_never_fabricated(): void
    {
        $result = $this->compute(
            events: [$this->event('fb1', '2026-06-03 12:00', '2026-06-03 13:00', summary: 'Busy', isFreeBusyOnly: true)],
            highlightWords: ['Alice'],
        );

        $this->assertCount(1, $result->unavailable);
        $this->assertEmpty($result->highlighted);
    }

    public function test_mixed_feed_applies_full_detail_matching_only_to_events_with_real_content(): void
    {
        $result = $this->compute(
            events: [
                $this->event('real', '2026-06-03 09:00', '2026-06-03 10:00', 'Coffee with Alice'),
                $this->event('generic', '2026-06-03 14:00', '2026-06-03 15:00', 'Busy', isFreeBusyOnly: true),
            ],
            highlightWords: ['Alice'],
        );

        $this->assertCount(1, $result->highlighted);
        $this->assertSame(['Alice'], $result->highlighted[0]->highlightWords);
        $this->assertCount(2, $result->unavailable);
    }

    public function test_free_ranges_are_computed_explicitly_within_the_wake_sleep_window(): void
    {
        $result = $this->compute(
            events: [$this->event('a', '2026-06-03 09:00', '2026-06-03 10:00', 'Meeting')],
            weeklyAvailability: $this->everyWeekday('08:00', '22:00'),
        );

        // Wednesday's awake window (08:00-22:00) minus the 09:00-10:00 event.
        $wednesdayFree = array_values(array_filter(
            $result->free,
            fn (AvailabilitySlot $s) => $s->start->toDateString() === '2026-06-03'
                && $s->start->toIso8601String() === '2026-06-03T08:00:00+00:00',
        ));
        $this->assertCount(1, $wednesdayFree);
        $this->assertSame('2026-06-03T09:00:00+00:00', $wednesdayFree[0]->end->toIso8601String());

        $afterMeeting = array_values(array_filter(
            $result->free,
            fn (AvailabilitySlot $s) => $s->start->toIso8601String() === '2026-06-03T10:00:00+00:00',
        ));
        $this->assertCount(1, $afterMeeting);
        $this->assertSame('2026-06-03T22:00:00+00:00', $afterMeeting[0]->end->toIso8601String());
    }

    public function test_a_fully_blank_day_window_is_awake_all_day_for_free_computation(): void
    {
        $result = $this->compute();

        // No wake/sleep configured at all — every day of the range is
        // fully free, with zero sleep entries. dayWindow()'s "whole day"
        // fallback used to be this day's endOfDay() (23:59:59.999999)
        // rather than the next day's startOfDay(), leaving a
        // 1-microsecond gap at every day boundary that computeSleepBlocks'
        // inversion picked up as a spurious degenerate sleep entry — user-
        // reported as "sleep records added even if no sleep times are
        // configured". Exact midnight-to-midnight windows close that gap.
        $this->assertEmpty($result->sleep);
        $this->assertNotEmpty($result->free);
    }
}
