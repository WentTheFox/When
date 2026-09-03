<?php

namespace Tests\Unit\Calendar;

use App\Services\Calendar\IcsParser;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class IcsParserTest extends TestCase
{
    private IcsParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new IcsParser;
    }

    private function fixture(string $name): string
    {
        return file_get_contents(__DIR__.'/../../Fixtures/ics/'.$name);
    }

    public function test_parses_full_detail_events_including_tentative_signals(): void
    {
        // A blank tentative pattern is genuinely off now (see
        // IcsParser::matchAndStrip's own doc comment) — the default constant
        // has to be passed explicitly to exercise its own "(?)" convention.
        $items = $this->parser->parse(
            $this->fixture('full_detail.ics'),
            CarbonImmutable::parse('2026-06-01', 'UTC'),
            CarbonImmutable::parse('2026-06-10', 'UTC'),
            tentativeTitlePattern: IcsParser::DEFAULT_TENTATIVE_TITLE_PATTERN,
        );

        $this->assertCount(3, $items);

        $coffee = $items[0];
        $this->assertSame('VEVENT', $coffee->componentType);
        $this->assertSame('Coffee with Alice', $coffee->summary);
        $this->assertSame('Downtown Cafe', $coffee->location);
        $this->assertFalse($coffee->tentativeStart);
        $this->assertFalse($coffee->tentativeEnd);

        // "(?)" title suffix signals fully tentative (both edges) and is stripped from the summary.
        $maybeLunch = $items[1];
        $this->assertSame('Maybe lunch', $maybeLunch->summary);
        $this->assertTrue($maybeLunch->tentativeStart);
        $this->assertTrue($maybeLunch->tentativeEnd);

        // STATUS:TENTATIVE also signals fully tentative.
        $standup = $items[2];
        $this->assertTrue($standup->tentativeStart);
        $this->assertTrue($standup->tentativeEnd);
    }

    public function test_a_blank_tentative_pattern_turns_title_detection_off_entirely(): void
    {
        // No tentativeTitlePattern passed at all — null, same as an owner
        // who's never set one. "(?)" is neither stripped nor treated as
        // tentative; only the structured STATUS:TENTATIVE signal survives.
        $items = $this->parser->parse(
            $this->fixture('full_detail.ics'),
            CarbonImmutable::parse('2026-06-01', 'UTC'),
            CarbonImmutable::parse('2026-06-10', 'UTC'),
        );

        $maybeLunch = $items[1];
        $this->assertSame('Maybe lunch (?)', $maybeLunch->summary);
        $this->assertFalse($maybeLunch->tentativeStart);
        $this->assertFalse($maybeLunch->tentativeEnd);

        $standup = $items[2];
        $this->assertTrue($standup->tentativeStart);
        $this->assertTrue($standup->tentativeEnd);
    }

    public function test_open_end_and_open_start_title_suffixes_set_only_one_edge(): void
    {
        // Same "blank is genuinely off now" reasoning as the tentative test
        // above — both default constants passed explicitly.
        $items = $this->parser->parse(
            $this->fixture('open_edges.ics'),
            CarbonImmutable::parse('2026-06-01', 'UTC'),
            CarbonImmutable::parse('2026-06-10', 'UTC'),
            openEndTitlePattern: IcsParser::DEFAULT_OPEN_END_TITLE_PATTERN,
            openStartTitlePattern: IcsParser::DEFAULT_OPEN_START_TITLE_PATTERN,
        );

        $this->assertCount(2, $items);

        // "(-?)" -> confirmed start, open (unknown) end.
        $dinner = $items[0];
        $this->assertSame('Dinner', $dinner->summary);
        $this->assertFalse($dinner->tentativeStart);
        $this->assertTrue($dinner->tentativeEnd);

        // "(?-)" -> open (unknown) start, confirmed end.
        $party = $items[1];
        $this->assertSame('Party', $party->summary);
        $this->assertTrue($party->tentativeStart);
        $this->assertFalse($party->tentativeEnd);
    }

    public function test_blank_open_end_and_open_start_patterns_turn_title_detection_off_entirely(): void
    {
        $items = $this->parser->parse(
            $this->fixture('open_edges.ics'),
            CarbonImmutable::parse('2026-06-01', 'UTC'),
            CarbonImmutable::parse('2026-06-10', 'UTC'),
        );

        $dinner = $items[0];
        $this->assertSame('Dinner (-?)', $dinner->summary);
        $this->assertFalse($dinner->tentativeStart);
        $this->assertFalse($dinner->tentativeEnd);

        $party = $items[1];
        $this->assertSame('Party (?-)', $party->summary);
        $this->assertFalse($party->tentativeStart);
        $this->assertFalse($party->tentativeEnd);
    }

    public function test_free_busy_only_mode_skips_title_suffix_patterns_but_not_status_tentative(): void
    {
        $items = $this->parser->parse(
            $this->fixture('full_detail.ics'),
            CarbonImmutable::parse('2026-06-01', 'UTC'),
            CarbonImmutable::parse('2026-06-10', 'UTC'),
            tentativeTitlePattern: IcsParser::DEFAULT_TENTATIVE_TITLE_PATTERN,
            parsingMode: 'free_busy_only',
        );

        // "(?)" is neither stripped nor treated as tentative when the title
        // regexes are gated off — this event's summary is fake title text.
        $maybeLunch = $items[1];
        $this->assertSame('Maybe lunch (?)', $maybeLunch->summary);
        $this->assertFalse($maybeLunch->tentativeStart);
        $this->assertFalse($maybeLunch->tentativeEnd);

        // STATUS:TENTATIVE is a structured ICS field, not title text — still
        // honored regardless of parsing mode.
        $standup = $items[2];
        $this->assertTrue($standup->tentativeStart);
        $this->assertTrue($standup->tentativeEnd);
    }

    public function test_free_busy_only_mode_also_gates_the_open_end_and_open_start_suffixes(): void
    {
        $items = $this->parser->parse(
            $this->fixture('open_edges.ics'),
            CarbonImmutable::parse('2026-06-01', 'UTC'),
            CarbonImmutable::parse('2026-06-10', 'UTC'),
            openEndTitlePattern: IcsParser::DEFAULT_OPEN_END_TITLE_PATTERN,
            openStartTitlePattern: IcsParser::DEFAULT_OPEN_START_TITLE_PATTERN,
            parsingMode: 'free_busy_only',
        );

        $dinner = $items[0];
        $this->assertSame('Dinner (-?)', $dinner->summary);
        $this->assertFalse($dinner->tentativeStart);
        $this->assertFalse($dinner->tentativeEnd);

        $party = $items[1];
        $this->assertSame('Party (?-)', $party->summary);
        $this->assertFalse($party->tentativeStart);
        $this->assertFalse($party->tentativeEnd);
    }

    public function test_parses_vfreebusy_blocks_and_skips_free_periods(): void
    {
        $items = $this->parser->parse(
            $this->fixture('free_busy_only.ics'),
            CarbonImmutable::parse('2026-06-01', 'UTC'),
            CarbonImmutable::parse('2026-06-10', 'UTC'),
        );

        // Only the BUSY and BUSY-TENTATIVE periods — the FREE one is dropped.
        $this->assertCount(2, $items);

        foreach ($items as $item) {
            $this->assertSame('VFREEBUSY', $item->componentType);
            $this->assertNull($item->summary);
        }

        $tentative = array_values(array_filter($items, fn ($i) => $i->tentativeStart && $i->tentativeEnd));
        $this->assertCount(1, $tentative);
    }

    public function test_drops_non_recurring_events_entirely_outside_the_requested_range(): void
    {
        $items = $this->parser->parse(
            $this->fixture('recurring_and_past.ics'),
            CarbonImmutable::parse('2026-06-01', 'UTC'),
            CarbonImmutable::parse('2026-06-15', 'UTC'),
        );

        $uids = array_map(fn ($i) => $i->uid, $items);

        $this->assertNotContains('old-nonrecurring@example.com', $uids);
    }

    public function test_expands_a_recurring_event_only_within_the_requested_range(): void
    {
        $items = $this->parser->parse(
            $this->fixture('recurring_and_past.ics'),
            CarbonImmutable::parse('2026-06-01', 'UTC'),
            CarbonImmutable::parse('2026-06-15', 'UTC'),
        );

        // Two Tuesdays (June 2 and June 9) fall in this 2-week window.
        $standups = array_values(array_filter(
            $items,
            fn ($i) => str_starts_with($i->uid, 'weekly-standup'),
        ));

        $this->assertCount(2, $standups);
        $this->assertSame('2026-06-02', $standups[0]->start->toDateString());
        $this->assertSame('2026-06-09', $standups[1]->start->toDateString());
    }

    public function test_a_custom_tentative_title_pattern_overrides_the_default(): void
    {
        // Starting from the default pattern, "(?)" is stripped/detected.
        $items = $this->parser->parse(
            $this->fixture('full_detail.ics'),
            CarbonImmutable::parse('2026-06-01', 'UTC'),
            CarbonImmutable::parse('2026-06-10', 'UTC'),
            tentativeTitlePattern: IcsParser::DEFAULT_TENTATIVE_TITLE_PATTERN,
        );
        $coffee = $items[0];
        $this->assertSame('Coffee with Alice', $coffee->summary);
        $this->assertFalse($coffee->tentativeStart);
        $this->assertFalse($coffee->tentativeEnd);

        // Swapping in a custom pattern changes what's detected/stripped
        // instead — the default "(?)" convention no longer applies at all.
        $items = $this->parser->parse(
            $this->fixture('full_detail.ics'),
            CarbonImmutable::parse('2026-06-01', 'UTC'),
            CarbonImmutable::parse('2026-06-10', 'UTC'),
            'Alice$',
        );
        $coffee = $items[0];
        $this->assertSame('Coffee with', $coffee->summary);
        $this->assertTrue($coffee->tentativeStart);
        $this->assertTrue($coffee->tentativeEnd);

        // And the "Maybe lunch (?)" event is no longer detected as
        // tentative by title (STATUS:TENTATIVE still applies independently).
        $maybeLunch = $items[1];
        $this->assertSame('Maybe lunch (?)', $maybeLunch->summary);
    }

    public function test_a_custom_open_end_pattern_overrides_the_default(): void
    {
        // Starting from the default pattern, "(-?)" is stripped/detected.
        $items = $this->parser->parse(
            $this->fixture('open_edges.ics'),
            CarbonImmutable::parse('2026-06-01', 'UTC'),
            CarbonImmutable::parse('2026-06-10', 'UTC'),
            openEndTitlePattern: IcsParser::DEFAULT_OPEN_END_TITLE_PATTERN,
        );
        $dinner = $items[0];
        $this->assertSame('Dinner', $dinner->summary);
        $this->assertTrue($dinner->tentativeEnd);
        $this->assertFalse($dinner->tentativeStart);

        // Swapping in a custom open-end pattern changes what's detected/
        // stripped instead — the default "(-?)" convention no longer
        // applies at all, so "Dinner (-?)" is left untouched and confirmed.
        $items = $this->parser->parse(
            $this->fixture('open_edges.ics'),
            CarbonImmutable::parse('2026-06-01', 'UTC'),
            CarbonImmutable::parse('2026-06-10', 'UTC'),
            null,
            'Alice$',
        );
        $dinner = $items[0];
        $this->assertSame('Dinner (-?)', $dinner->summary);
        $this->assertFalse($dinner->tentativeStart);
        $this->assertFalse($dinner->tentativeEnd);
    }
}
