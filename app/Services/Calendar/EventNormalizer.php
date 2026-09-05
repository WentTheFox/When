<?php

namespace App\Services\Calendar;

use App\Domain\Calendar\ParsedEvent;
use App\Domain\Calendar\RawCalendarItem;

/**
 * Turns {@see RawCalendarItem}s into {@see ParsedEvent}s, applying the
 * owner's pinned parsing mode uniformly to every event (§5.0).
 *
 * `calendar_parsing_mode` is always one of the two concrete values below —
 * there's no more per-event heuristic here. {@see FeedClassifier} still
 * exists for the diagnostic "detected feed type" preview label and the
 * `calendar_detections` audit log, but nothing in this class reads it.
 */
class EventNormalizer
{
    /**
     * @param  RawCalendarItem[]  $items
     * @param  'full_detail'|'free_busy_only'  $parsingMode
     * @return ParsedEvent[]
     */
    public function normalize(array $items, string $parsingMode): array
    {
        $isFreeBusyOnly = $parsingMode === 'free_busy_only';

        return array_map(
            fn (RawCalendarItem $item) => new ParsedEvent(
                uid: $item->uid,
                start: $item->start,
                end: $item->end,
                summary: $item->summary,
                description: $item->description,
                location: $item->location,
                isFreeBusyOnly: $isFreeBusyOnly,
                tentativeStart: $item->tentativeStart,
                tentativeEnd: $item->tentativeEnd,
                isPublicEventTitle: $item->isPublicEventTitle,
            ),
            $items,
        );
    }
}
