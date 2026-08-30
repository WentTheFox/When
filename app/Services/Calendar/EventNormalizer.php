<?php

namespace App\Services\Calendar;

use App\Domain\Calendar\ParsedEvent;
use App\Domain\Calendar\RawCalendarItem;

/**
 * Turns {@see RawCalendarItem}s into {@see ParsedEvent}s, deciding per event
 * whether full-detail matching should even be attempted (§5.0).
 *
 * A manual pin overrides the heuristic entirely for every event; `auto`
 * decides per event via the same generic-summary check the feed-level
 * classifier uses — which is what gives "mixed" feeds their per-event
 * degradation for free, without a separate code path.
 */
class EventNormalizer
{
    public function __construct(private readonly FeedClassifier $classifier) {}

    /**
     * @param  RawCalendarItem[]  $items
     * @param  'full_detail'|'free_busy_only'|'auto'  $parsingMode
     * @return ParsedEvent[]
     */
    public function normalize(array $items, string $parsingMode): array
    {
        return array_map(
            fn (RawCalendarItem $item) => new ParsedEvent(
                uid: $item->uid,
                start: $item->start,
                end: $item->end,
                summary: $item->summary,
                description: $item->description,
                location: $item->location,
                isFreeBusyOnly: $this->isFreeBusyOnly($item, $parsingMode),
                isTentative: $item->isTentative,
            ),
            $items,
        );
    }

    private function isFreeBusyOnly(RawCalendarItem $item, string $parsingMode): bool
    {
        return match ($parsingMode) {
            'full_detail' => false,
            'free_busy_only' => true,
            default => $this->classifier->isGeneric($item),
        };
    }
}
