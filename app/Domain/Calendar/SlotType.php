<?php

namespace App\Domain\Calendar;

/**
 * Precedence when intervals overlap, highest first (§5.1): sleep always
 * wins over a conflicting event; tentative is its own state, never merged
 * into free or firmly-unavailable; a highlighted match is a distinct,
 * more-specific busy state than plain busy.
 */
enum SlotType: string
{
    case Sleep = 'sleep';
    case Tentative = 'tentative';
    case Highlighted = 'highlighted';
    case Busy = 'busy';

    public function precedence(): int
    {
        return match ($this) {
            self::Sleep => 4,
            self::Tentative => 3,
            self::Highlighted => 2,
            self::Busy => 1,
        };
    }
}
