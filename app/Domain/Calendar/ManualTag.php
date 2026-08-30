<?php

namespace App\Domain\Calendar;

/**
 * Decrypted, in-memory form of a share_link_manual_tags row — §5.0's
 * fallback for free_busy_only/mixed feeds where there's no real title to
 * match against.
 */
final class ManualTag
{
    public function __construct(
        public readonly string $word,
        /** 0 (Sunday) .. 6 (Saturday), or null for "every day." */
        public readonly ?int $weekday,
        /** "HH:MM" 24-hour, in the calendar owner's timezone. */
        public readonly string $startTime,
        public readonly string $endTime,
    ) {}

    public function matchesWeekdayAndTime(int $weekday, string $timeOfDay): bool
    {
        if ($this->weekday !== null && $this->weekday !== $weekday) {
            return false;
        }

        return $timeOfDay >= $this->startTime && $timeOfDay < $this->endTime;
    }
}
