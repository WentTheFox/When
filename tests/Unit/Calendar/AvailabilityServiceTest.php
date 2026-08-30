<?php

namespace Tests\Unit\Calendar;

use App\Domain\Calendar\AvailabilitySlot;
use App\Domain\Calendar\ManualTag;
use App\Domain\Calendar\ParsedEvent;
use App\Domain\Calendar\SlotType;
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
        $this->service = new AvailabilityService(new HighlightMatcher());
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
    ): array {
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
        );
    }

    public function test_default_sleep_blocks_are_generated_per_weekday_window(): void
    {
        $slots = $this->compute(weeklyAvailability: $this->everyWeekday('07:00', '23:00'));

        $sleepSlots = array_values(array_filter($slots, fn (AvailabilitySlot $s) => $s->type === SlotType::Sleep));
        $this->assertNotEmpty($sleepSlots);

        // Monday night: 23:00 -> Tuesday 07:00. (The range also starts with a
        // sleep block clipped from the previous Sunday night, since a sleep
        // window can span into the range from just before it starts.)
        $mondayNight = array_values(array_filter(
            $sleepSlots,
            fn (AvailabilitySlot $s) => $s->start->toIso8601String() === '2026-06-01T23:00:00+00:00',
        ));
        $this->assertCount(1, $mondayNight);
        $this->assertSame('2026-06-02T07:00:00+00:00', $mondayNight[0]->end->toIso8601String());
    }

    public function test_sleep_exception_suppresses_the_default_sleep_block_for_that_date(): void
    {
        $slots = $this->compute(
            weeklyAvailability: $this->everyWeekday('07:00', '23:00'),
            sleepExceptions: [[
                'start' => CarbonImmutable::parse('2026-06-02', 'UTC'),
                'end' => CarbonImmutable::parse('2026-06-02', 'UTC'),
            ]],
        );

        $sleepSlots = array_filter($slots, fn (AvailabilitySlot $s) => $s->type === SlotType::Sleep);

        foreach ($sleepSlots as $slot) {
            // No sleep block should START on 2026-06-02 (the excepted date).
            $this->assertNotSame('2026-06-02', $slot->start->toDateString());
        }

        // The night before (Mon->Tue) and after (Wed->Thu) are unaffected.
        $starts = array_map(fn (AvailabilitySlot $s) => $s->start->toDateString(), $sleepSlots);
        $this->assertContains('2026-06-01', $starts);
        $this->assertContains('2026-06-03', $starts);
    }

    public function test_nap_events_are_merged_into_sleep_rather_than_shown_as_busy(): void
    {
        $slots = $this->compute(
            events: [$this->event('nap-1', '2026-06-03 14:00', '2026-06-03 15:00', 'Afternoon Nap')],
            napEventName: 'Afternoon Nap',
        );

        $this->assertCount(1, $slots);
        $this->assertSame(SlotType::Sleep, $slots[0]->type);
        $this->assertSame('2026-06-03T14:00:00+00:00', $slots[0]->start->toIso8601String());
    }

    public function test_dnd_events_are_excluded_by_default(): void
    {
        $slots = $this->compute(
            events: [$this->event('dnd-1', '2026-06-03 09:00', '2026-06-03 10:00', 'Therapy')],
            dndEventName: 'Therapy',
        );

        $this->assertEmpty($slots);
    }

    public function test_dnd_bypass_share_links_see_the_dnd_event(): void
    {
        $slots = $this->compute(
            events: [$this->event('dnd-1', '2026-06-03 09:00', '2026-06-03 10:00', 'Therapy')],
            dndEventName: 'Therapy',
            bypassDnd: true,
        );

        $this->assertCount(1, $slots);
        $this->assertSame(SlotType::Busy, $slots[0]->type);
    }

    public function test_sleep_takes_precedence_over_a_conflicting_busy_event(): void
    {
        $slots = $this->compute(
            events: [$this->event('late-call', '2026-06-01 22:30', '2026-06-01 23:30', 'Late call')],
            weeklyAvailability: $this->everyWeekday('07:00', '23:00'),
        );

        // The overlapping 30 minutes (23:00-23:30) must be sleep, not busy —
        // and the busy portion before it (22:30-23:00) must survive.
        $busySlots = array_values(array_filter($slots, fn (AvailabilitySlot $s) => $s->type === SlotType::Busy));
        $this->assertCount(1, $busySlots);
        $this->assertSame('2026-06-01T22:30:00+00:00', $busySlots[0]->start->toIso8601String());
        $this->assertSame('2026-06-01T23:00:00+00:00', $busySlots[0]->end->toIso8601String());

        $sleepSlots = array_values(array_filter($slots, fn (AvailabilitySlot $s) => $s->type === SlotType::Sleep));
        $overnightSleep = array_values(array_filter(
            $sleepSlots,
            fn (AvailabilitySlot $s) => $s->start->toIso8601String() === '2026-06-01T23:00:00+00:00',
        ));
        $this->assertCount(1, $overnightSleep);
    }

    public function test_tentative_events_render_as_their_own_distinct_state(): void
    {
        $slots = $this->compute(
            events: [$this->event('maybe', '2026-06-03 15:00', '2026-06-03 16:00', 'Maybe lunch', isTentative: true)],
        );

        $this->assertCount(1, $slots);
        $this->assertSame(SlotType::Tentative, $slots[0]->type);
    }

    public function test_back_to_back_busy_events_merge_into_one_continuous_block(): void
    {
        $slots = $this->compute(events: [
            $this->event('a', '2026-06-03 09:00', '2026-06-03 10:00', 'Meeting A'),
            $this->event('b', '2026-06-03 10:00', '2026-06-03 11:00', 'Meeting B'),
        ]);

        $this->assertCount(1, $slots);
        $this->assertSame(SlotType::Busy, $slots[0]->type);
        $this->assertSame('2026-06-03T09:00:00+00:00', $slots[0]->start->toIso8601String());
        $this->assertSame('2026-06-03T11:00:00+00:00', $slots[0]->end->toIso8601String());
    }

    public function test_overlapping_busy_events_merge_into_one_continuous_block(): void
    {
        $slots = $this->compute(events: [
            $this->event('a', '2026-06-03 09:00', '2026-06-03 10:30', 'Meeting A'),
            $this->event('b', '2026-06-03 10:00', '2026-06-03 11:00', 'Meeting B'),
        ]);

        $this->assertCount(1, $slots);
        $this->assertSame('2026-06-03T09:00:00+00:00', $slots[0]->start->toIso8601String());
        $this->assertSame('2026-06-03T11:00:00+00:00', $slots[0]->end->toIso8601String());
    }

    public function test_full_detail_with_clause_matches_a_configured_highlight_word(): void
    {
        $slots = $this->compute(
            events: [$this->event('c1', '2026-06-03 12:00', '2026-06-03 13:00', 'Coffee with Alice')],
            highlightWords: ['Alice', 'Bob'],
        );

        $this->assertCount(1, $slots);
        $this->assertSame(SlotType::Highlighted, $slots[0]->type);
        $this->assertSame('Alice', $slots[0]->highlightWord);
    }

    public function test_full_detail_event_with_no_matching_clause_is_plain_busy(): void
    {
        $slots = $this->compute(
            events: [$this->event('c1', '2026-06-03 12:00', '2026-06-03 13:00', 'Team standup')],
            highlightWords: ['Alice'],
        );

        $this->assertCount(1, $slots);
        $this->assertSame(SlotType::Busy, $slots[0]->type);
        $this->assertNull($slots[0]->highlightWord);
    }

    public function test_free_busy_only_event_matches_via_location_not_title(): void
    {
        $slots = $this->compute(
            events: [$this->event(
                'fb1', '2026-06-03 12:00', '2026-06-03 13:00',
                summary: 'Busy', location: 'Alice', isFreeBusyOnly: true,
            )],
            highlightWords: ['Alice'],
        );

        $this->assertCount(1, $slots);
        $this->assertSame(SlotType::Highlighted, $slots[0]->type);
        $this->assertSame('Alice', $slots[0]->highlightWord);
    }

    public function test_free_busy_only_event_falls_back_to_a_manual_time_block_tag(): void
    {
        // 2026-06-03 is a Wednesday.
        $slots = $this->compute(
            events: [$this->event(
                'fb1', '2026-06-03 12:00', '2026-06-03 13:00',
                summary: 'Busy', isFreeBusyOnly: true,
            )],
            manualTags: [new ManualTag(word: 'Gym', weekday: 3, startTime: '12:00', endTime: '14:00')],
        );

        $this->assertCount(1, $slots);
        $this->assertSame(SlotType::Highlighted, $slots[0]->type);
        $this->assertSame('Gym', $slots[0]->highlightWord);
    }

    public function test_free_busy_only_event_with_no_signal_is_plain_busy_never_fabricated(): void
    {
        $slots = $this->compute(
            events: [$this->event('fb1', '2026-06-03 12:00', '2026-06-03 13:00', summary: 'Busy', isFreeBusyOnly: true)],
            highlightWords: ['Alice'],
        );

        $this->assertCount(1, $slots);
        $this->assertSame(SlotType::Busy, $slots[0]->type);
    }

    public function test_mixed_feed_applies_full_detail_matching_only_to_events_with_real_content(): void
    {
        $slots = $this->compute(
            events: [
                $this->event('real', '2026-06-03 09:00', '2026-06-03 10:00', 'Coffee with Alice'),
                $this->event('generic', '2026-06-03 14:00', '2026-06-03 15:00', 'Busy', isFreeBusyOnly: true),
            ],
            highlightWords: ['Alice'],
        );

        $this->assertCount(2, $slots);

        $highlighted = array_values(array_filter($slots, fn (AvailabilitySlot $s) => $s->type === SlotType::Highlighted));
        $busy = array_values(array_filter($slots, fn (AvailabilitySlot $s) => $s->type === SlotType::Busy));

        $this->assertCount(1, $highlighted);
        $this->assertSame('Alice', $highlighted[0]->highlightWord);
        $this->assertCount(1, $busy);
    }

    public function test_everything_outside_computed_slots_is_implicitly_free(): void
    {
        $slots = $this->compute(
            events: [$this->event('a', '2026-06-03 09:00', '2026-06-03 10:00', 'Meeting')],
        );

        // Only the one busy block — no explicit "free" slots are ever emitted.
        $this->assertCount(1, $slots);
        $this->assertSame(SlotType::Busy, $slots[0]->type);
    }
}
