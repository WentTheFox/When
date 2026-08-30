<?php

namespace Tests\Unit\Calendar;

use App\Domain\Calendar\ManualTag;
use App\Domain\Calendar\ParsedEvent;
use App\Services\Calendar\HighlightMatcher;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class HighlightMatcherTest extends TestCase
{
    private HighlightMatcher $matcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->matcher = new HighlightMatcher();
    }

    private function event(?string $summary = null, ?string $description = null, ?string $location = null, bool $isFreeBusyOnly = false): ParsedEvent
    {
        return new ParsedEvent(
            uid: 'x',
            start: CarbonImmutable::parse('2026-06-03 09:00', 'UTC'),
            end: CarbonImmutable::parse('2026-06-03 10:00', 'UTC'),
            summary: $summary,
            description: $description,
            location: $location,
            isFreeBusyOnly: $isFreeBusyOnly,
        );
    }

    public function test_matches_the_abbreviated_w_slash_clause(): void
    {
        $result = $this->matcher->match($this->event(summary: 'Dinner w/ Bob'), ['Bob'], []);
        $this->assertSame('Bob', $result);
    }

    public function test_clause_matching_is_case_insensitive(): void
    {
        $result = $this->matcher->match($this->event(summary: 'Dinner WITH bob'), ['Bob'], []);
        $this->assertSame('Bob', $result);
    }

    public function test_falls_back_to_description_when_summary_has_no_clause(): void
    {
        $result = $this->matcher->match(
            $this->event(summary: 'Dinner', description: 'catching up with Carol'),
            ['Carol'],
            [],
        );
        $this->assertSame('Carol', $result);
    }

    public function test_never_reveals_the_raw_title_when_nothing_matches(): void
    {
        $result = $this->matcher->match($this->event(summary: 'Coffee with Some Stranger'), ['Alice'], []);
        $this->assertNull($result);
    }

    public function test_full_detail_event_can_still_fall_back_to_location_if_no_clause_matches(): void
    {
        $result = $this->matcher->match(
            $this->event(summary: 'Team sync', location: 'Alice'),
            ['Alice'],
            [],
        );
        $this->assertSame('Alice', $result);
    }

    public function test_manual_tag_with_null_weekday_matches_every_day(): void
    {
        $tag = new ManualTag(word: 'Commute', weekday: null, startTime: '08:00', endTime: '09:00');
        $event = new ParsedEvent(
            uid: 'x',
            start: CarbonImmutable::parse('2026-06-03 08:30', 'UTC'), // Wednesday
            end: CarbonImmutable::parse('2026-06-03 09:00', 'UTC'),
            isFreeBusyOnly: true,
        );

        $this->assertSame('Commute', $this->matcher->match($event, [], [$tag]));
    }

    public function test_manual_tag_outside_its_time_window_does_not_match(): void
    {
        $tag = new ManualTag(word: 'Commute', weekday: null, startTime: '08:00', endTime: '09:00');
        $event = new ParsedEvent(
            uid: 'x',
            start: CarbonImmutable::parse('2026-06-03 14:00', 'UTC'),
            end: CarbonImmutable::parse('2026-06-03 15:00', 'UTC'),
            isFreeBusyOnly: true,
        );

        $this->assertNull($this->matcher->match($event, [], [$tag]));
    }

    public function test_a_custom_clause_pattern_overrides_the_built_in_with_w_slash_default(): void
    {
        $event = $this->event(summary: 'Focus session w: Bob');

        // The default pattern doesn't recognize "w:" — only "with"/"w/".
        $this->assertNull($this->matcher->match($event, ['Bob'], []));

        $result = $this->matcher->match($event, ['Bob'], [], 'w:\s+(.+)$');
        $this->assertSame('Bob', $result);
    }

    public function test_an_invalid_custom_clause_pattern_fails_closed_instead_of_throwing(): void
    {
        $event = $this->event(summary: 'Coffee with Alice');

        $result = $this->matcher->match($event, ['Alice'], [], '(');

        $this->assertNull($result);
    }
}
