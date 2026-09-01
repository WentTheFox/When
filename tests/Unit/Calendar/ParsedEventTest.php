<?php

namespace Tests\Unit\Calendar;

use App\Domain\Calendar\ParsedEvent;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class ParsedEventTest extends TestCase
{
    private function event(?string $summary, bool $isFreeBusyOnly = false): ParsedEvent
    {
        return new ParsedEvent(
            uid: 'x',
            start: CarbonImmutable::now(),
            end: CarbonImmutable::now()->addHour(),
            summary: $summary,
            isFreeBusyOnly: $isFreeBusyOnly,
        );
    }

    public function test_a_plain_literal_pattern_still_works_as_an_exact_style_match(): void
    {
        $this->assertTrue($this->event('Sleep')->matchesEventNamePattern('Sleep'));
        $this->assertTrue($this->event('sleep')->matchesEventNamePattern('Sleep')); // case-insensitive
    }

    public function test_a_pattern_matches_anywhere_in_the_summary_unless_anchored(): void
    {
        $this->assertTrue($this->event('My Sleep Block')->matchesEventNamePattern('Sleep'));
    }

    public function test_supports_real_regex_alternation(): void
    {
        $event = $this->event('Nap time');
        $this->assertTrue($event->matchesEventNamePattern('^(Sleep|Nap)'));
        $this->assertFalse($this->event('Focus block')->matchesEventNamePattern('^(Sleep|Nap)'));
    }

    public function test_supports_wildcard_patterns(): void
    {
        $this->assertTrue($this->event('Focus: Deep Work Block')->matchesEventNamePattern('Focus.*Block'));
    }

    public function test_null_or_empty_pattern_never_matches(): void
    {
        $this->assertFalse($this->event('Sleep')->matchesEventNamePattern(null));
        $this->assertFalse($this->event('Sleep')->matchesEventNamePattern(''));
    }

    public function test_null_summary_never_matches(): void
    {
        $this->assertFalse($this->event(null)->matchesEventNamePattern('Sleep'));
    }

    public function test_an_invalid_regex_pattern_fails_closed_instead_of_throwing(): void
    {
        // Unbalanced group — invalid PCRE.
        $this->assertFalse($this->event('Sleep')->matchesEventNamePattern('('));
    }

    public function test_free_busy_only_events_never_match_even_an_exact_summary(): void
    {
        // Summary literally equals the pattern — would match if this event
        // weren't free-busy-only, since that summary is a fake generic
        // placeholder (e.g. "Busy"), not real title text.
        $this->assertFalse($this->event('Sleep', isFreeBusyOnly: true)->matchesEventNamePattern('Sleep'));
    }
}
