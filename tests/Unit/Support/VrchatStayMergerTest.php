<?php

namespace Tests\Unit\Support;

use App\Support\VrchatStayMerger;
use PHPUnit\Framework\TestCase;

class VrchatStayMergerTest extends TestCase
{
    private function stay(string $start, string $end, int $others = 0): array
    {
        $ms = (new \DateTimeImmutable($end))->getTimestamp() - (new \DateTimeImmutable($start))->getTimestamp();

        return ['start' => $start, 'end' => $end, 'duration_ms' => $ms * 1000, 'other_player_count' => $others];
    }

    public function test_a_world_switch_gap_joins_two_stays_into_one_block(): void
    {
        $merged = VrchatStayMerger::merge([
            $this->stay('2026-10-01T10:00:00.000Z', '2026-10-01T11:00:00.000Z'),
            $this->stay('2026-10-01T11:08:00.000Z', '2026-10-01T12:00:00.000Z'),
        ]);

        $this->assertCount(1, $merged);
        $this->assertSame('2026-10-01T10:00:00.000Z', $merged[0]['start']);
        $this->assertSame('2026-10-01T12:00:00.000Z', $merged[0]['end']);
        $this->assertSame(120 * 60000, $merged[0]['duration_ms'], 'the gap counts: one consecutive block');
    }

    public function test_a_longer_gap_keeps_the_stays_apart(): void
    {
        $merged = VrchatStayMerger::merge([
            $this->stay('2026-10-01T10:00:00.000Z', '2026-10-01T11:00:00.000Z'),
            $this->stay('2026-10-01T11:16:00.000Z', '2026-10-01T12:00:00.000Z'),
        ]);

        $this->assertCount(2, $merged);
    }

    public function test_a_gap_of_exactly_the_limit_still_joins(): void
    {
        $merged = VrchatStayMerger::merge([
            $this->stay('2026-10-01T10:00:00.000Z', '2026-10-01T11:00:00.000Z'),
            $this->stay('2026-10-01T11:15:00.000Z', '2026-10-01T12:00:00.000Z'),
        ]);

        $this->assertCount(1, $merged);
    }

    public function test_overlapping_nested_and_unsorted_stays_collapse_to_the_widest_block(): void
    {
        $merged = VrchatStayMerger::merge([
            $this->stay('2026-10-01T10:30:00.000Z', '2026-10-01T10:40:00.000Z'),
            $this->stay('2026-10-01T12:00:00.000Z', '2026-10-01T13:00:00.000Z'),
            $this->stay('2026-10-01T10:00:00.000Z', '2026-10-01T11:00:00.000Z'),
            $this->stay('2026-10-01T10:50:00.000Z', '2026-10-01T12:05:00.000Z'),
        ]);

        $this->assertCount(1, $merged);
        $this->assertSame('2026-10-01T10:00:00.000Z', $merged[0]['start']);
        $this->assertSame('2026-10-01T13:00:00.000Z', $merged[0]['end']);
    }

    public function test_the_block_is_only_one_on_one_if_every_part_was(): void
    {
        $merged = VrchatStayMerger::merge([
            $this->stay('2026-10-01T10:00:00.000Z', '2026-10-01T11:00:00.000Z', 0),
            $this->stay('2026-10-01T11:05:00.000Z', '2026-10-01T12:00:00.000Z', 4),
        ]);

        $this->assertSame(4, $merged[0]['other_player_count']);
    }

    public function test_merging_already_merged_blocks_with_fresh_data_changes_nothing(): void
    {
        $raw = [
            $this->stay('2026-10-01T10:00:00.000Z', '2026-10-01T11:00:00.000Z'),
            $this->stay('2026-10-01T11:08:00.000Z', '2026-10-01T12:00:00.000Z'),
        ];

        $once = VrchatStayMerger::merge($raw);

        $this->assertSame($once, VrchatStayMerger::merge([...$once, ...$raw]));
    }

    public function test_unusable_intervals_are_dropped(): void
    {
        $merged = VrchatStayMerger::merge([
            ['start' => 'garbage', 'end' => '2026-10-01T11:00:00.000Z'],
            $this->stay('2026-10-01T11:00:00.000Z', '2026-10-01T10:00:00.000Z'),
        ]);

        $this->assertSame([], $merged);
    }
}
