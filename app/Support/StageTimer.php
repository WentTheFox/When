<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Diagnostic stage-timing for the availability-computation pipeline (see
 * config/logging.php's "availability" channel) — deployed so we can see
 * where time is actually going once real calendars/rule counts are in
 * play, without waiting for a slow request to be reported.
 *
 * Every instance gets its own trace_id (a fresh UUID, not any owner/share-
 * link identifier) so every stage of one run groups together in the log;
 * pass safe, non-secret context (ids, counts) via $context/$extra — never
 * calendar_url, event titles/summaries, or highlight words. See
 * CalendarUrlNeverLoggedTest/PlaintextLeakRegressionTest, which this must
 * keep satisfying.
 */
final class StageTimer
{
    public readonly string $traceId;

    private float $start;

    private float $lastLap;

    public function __construct(
        private readonly string $pipeline,
        private readonly array $context = [],
    ) {
        $this->traceId = (string) Str::uuid();
        $this->start = microtime(true);
        $this->lastLap = $this->start;
    }

    /** Logs how long it's been since the previous lap() (or construction) as one named stage. */
    public function lap(string $stage, array $extra = []): void
    {
        $now = microtime(true);

        Log::channel('availability')->info("{$this->pipeline}.{$stage}", [
            ...$this->context,
            ...$extra,
            'trace_id' => $this->traceId,
            'stage' => $stage,
            'duration_ms' => round(($now - $this->lastLap) * 1000, 2),
            'elapsed_ms' => round(($now - $this->start) * 1000, 2),
        ]);

        $this->lastLap = $now;
    }

    /** For a stage that errored out — no calendar_url/message content, just that it failed and when. */
    public function fail(string $stage, array $extra = []): void
    {
        $now = microtime(true);

        Log::channel('availability')->warning("{$this->pipeline}.{$stage}.failed", [
            ...$this->context,
            ...$extra,
            'trace_id' => $this->traceId,
            'stage' => $stage,
            'elapsed_ms' => round(($now - $this->start) * 1000, 2),
        ]);
    }
}
