<?php

namespace Tests\Unit;

use App\Domain\Calendar\AvailabilityResult;
use App\Domain\Calendar\AvailabilitySlot;
use App\Http\Controllers\DashboardController;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class DashboardAvailabilityRowTest extends TestCase
{
    private function row(array $slots, int $days): array
    {
        $start = CarbonImmutable::parse('2026-03-02 00:00:00', 'UTC');
        $end = $start->addDays($days);
        $method = new \ReflectionMethod(DashboardController::class, 'buildRow');

        return $method->invoke(
            (new \ReflectionClass(DashboardController::class))->newInstanceWithoutConstructor(),
            'Test', new AvailabilityResult($slots), $start, $end, $days,
        );
    }

    private function slot(string $type, int $fromHour, int $toHour, int $day = 0): AvailabilitySlot
    {
        $base = CarbonImmutable::parse('2026-03-02 00:00:00', 'UTC')->addDays($day);

        return new AvailabilitySlot($base->addHours($fromHour), $base->addHours($toHour), $type);
    }

    public function test_percentages_are_shares_of_the_whole_period_and_sum_to_100(): void
    {
        // 8h sleep, 6h free, so 10h busy out of 24h.
        $row = $this->row([$this->slot('sleep', 0, 8), $this->slot('free', 8, 14)], 1);

        $this->assertSame(33, $row['sleepPct']);
        $this->assertSame(42, $row['busyPct']);
        $this->assertSame(25, $row['freePct']);
        $this->assertSame(100, $row['sleepPct'] + $row['busyPct'] + $row['freePct']);
        $this->assertSame('8:00', $row['sleepLabel']);
        $this->assertSame('10:00', $row['busyLabel']);
        $this->assertSame('6:00', $row['freeLabel']);
    }

    public function test_bars_match_the_displayed_percentages(): void
    {
        $row = $this->row([$this->slot('sleep', 0, 8), $this->slot('free', 8, 14)], 1);

        $this->assertSame($row['sleepPct'], $row['sleepBarPct']);
        $this->assertSame($row['busyPct'], $row['busyBarPct']);
    }

    public function test_rounding_never_pushes_the_total_past_100(): void
    {
        // Thirds across 3 days: each rounds to 33/33/33 individually.
        $slots = [$this->slot('sleep', 0, 24), $this->slot('free', 0, 24, 1)];
        $row = $this->row($slots, 3);

        $this->assertSame(100, $row['sleepPct'] + $row['busyPct'] + $row['freePct']);
    }

    public function test_all_sleep_period_is_marked_unavailable(): void
    {
        $row = $this->row([$this->slot('sleep', 0, 24)], 1);

        $this->assertTrue($row['notAvail']);
        $this->assertSame(100, $row['sleepPct']);
        $this->assertNull($row['freePct']);
    }
}
