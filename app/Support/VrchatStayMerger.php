<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Joins VRChat shared-instance intervals that are close enough together to be
 * one session: switching worlds or re-logging leaves a few minutes between
 * two intervals, but the time together is one continuous block.
 *
 * Idempotent on purpose: merged output fed back in (alongside fresh data)
 * yields the same blocks. start/end keep the original strings of the
 * earliest start and the latest end, so a block keeps its dedupe key across
 * syncs, and duration_ms is end - start (the gaps count), not a sum.
 */
final class VrchatStayMerger
{
    /** Gaps up to this long (world switch, re-login) join two intervals. */
    public const GAP_MINUTES = 15;

    /**
     * @param  list<array<array-key, mixed>>  $intervals  Fresh VRCX rows and stored payloads; anything unparsable is dropped.
     * @return list<array{start: string, end: string, duration_ms: int, other_player_count: int}>
     */
    public static function merge(array $intervals, int $gapMinutes = self::GAP_MINUTES): array
    {
        $parsed = [];

        foreach ($intervals as $interval) {
            try {
                $start = CarbonImmutable::parse((string) ($interval['start'] ?? ''));
                $end = CarbonImmutable::parse((string) ($interval['end'] ?? ''));
            } catch (\Throwable) {
                continue;
            }

            if ($end->gt($start)) {
                $parsed[] = [
                    'start' => $start,
                    'end' => $end,
                    'startRaw' => (string) ($interval['start'] ?? ''),
                    'endRaw' => (string) ($interval['end'] ?? ''),
                    'others' => max(0, (int) ($interval['other_player_count'] ?? 0)),
                ];
            }
        }

        usort($parsed, fn (array $a, array $b) => $a['start'] <=> $b['start']);

        $blocks = [];

        foreach ($parsed as $p) {
            $last = array_key_last($blocks);

            if ($last !== null && $p['start']->lte($blocks[$last]['end']->addMinutes($gapMinutes))) {
                if ($p['end']->gt($blocks[$last]['end'])) {
                    $blocks[$last]['end'] = $p['end'];
                    $blocks[$last]['endRaw'] = $p['endRaw'];
                }
                $blocks[$last]['others'] = max($blocks[$last]['others'], $p['others']);

                continue;
            }

            $blocks[] = $p;
        }

        return array_map(fn (array $b) => [
            'start' => $b['startRaw'],
            'end' => $b['endRaw'],
            'duration_ms' => (int) $b['start']->diffInMilliseconds($b['end'], true),
            'other_player_count' => $b['others'],
        ], $blocks);
    }
}
