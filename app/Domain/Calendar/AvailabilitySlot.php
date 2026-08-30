<?php

namespace App\Domain\Calendar;

use Carbon\CarbonImmutable;

/**
 * A single computed, merged block in the final free/busy result. Anything
 * NOT covered by a slot is implicitly free — the API only ever transmits
 * the non-free blocks.
 */
final class AvailabilitySlot
{
    public function __construct(
        public readonly SlotType $type,
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly ?string $highlightWord = null,
    ) {}

    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'start' => $this->start->toIso8601String(),
            'end' => $this->end->toIso8601String(),
            'highlight_word' => $this->highlightWord,
        ];
    }
}
