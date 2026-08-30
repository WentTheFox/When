<?php

namespace Tests\Unit\Calendar;

use App\Domain\Calendar\FeedMode;
use App\Domain\Calendar\RawCalendarItem;
use App\Services\Calendar\FeedClassifier;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class FeedClassifierTest extends TestCase
{
    private FeedClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new FeedClassifier();
    }

    private function item(string $componentType, ?string $summary): RawCalendarItem
    {
        return new RawCalendarItem(
            uid: 'x',
            start: CarbonImmutable::now(),
            end: CarbonImmutable::now()->addHour(),
            componentType: $componentType,
            summary: $summary,
        );
    }

    public function test_a_feed_of_real_titles_classifies_as_full_detail(): void
    {
        $items = [
            $this->item('VEVENT', 'Coffee with Alice'),
            $this->item('VEVENT', 'Team standup'),
        ];

        $this->assertSame(FeedMode::FullDetail, $this->classifier->classify($items));
    }

    public function test_a_feed_dominated_by_generic_summaries_classifies_as_free_busy_only(): void
    {
        $items = [
            $this->item('VEVENT', 'Busy'),
            $this->item('VEVENT', 'Blocked'),
            $this->item('VEVENT', ''),
        ];

        $this->assertSame(FeedMode::FreeBusyOnly, $this->classifier->classify($items));
    }

    public function test_a_feed_of_only_vfreebusy_blocks_classifies_as_free_busy_only(): void
    {
        $items = [$this->item('VFREEBUSY', null), $this->item('VFREEBUSY', null)];

        $this->assertSame(FeedMode::FreeBusyOnly, $this->classifier->classify($items));
    }

    public function test_a_mix_of_real_and_generic_summaries_classifies_as_mixed(): void
    {
        $items = [
            $this->item('VEVENT', 'Coffee with Alice'),
            $this->item('VEVENT', 'Busy'),
        ];

        $this->assertSame(FeedMode::Mixed, $this->classifier->classify($items));
    }

    public function test_generic_summary_matching_is_case_insensitive(): void
    {
        $item = $this->item('VEVENT', 'BUSY');
        $this->assertTrue($this->classifier->isGeneric($item));
    }

    public function test_an_empty_feed_defaults_to_full_detail_rather_than_guessing(): void
    {
        $this->assertSame(FeedMode::FullDetail, $this->classifier->classify([]));
    }
}
