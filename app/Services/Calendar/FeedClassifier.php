<?php

namespace App\Services\Calendar;

use App\Domain\Calendar\FeedMode;
use App\Domain\Calendar\RawCalendarItem;

/**
 * §5.0: classifies a feed by how much real event detail it actually
 * exposes, so matching behavior can degrade gracefully instead of silently
 * never firing on a redacted feed.
 */
class FeedClassifier
{
    /** Case-insensitive — a feed redacted to "Busy"/"Blocked"/etc. carries no real content. */
    private const GENERIC_SUMMARY_STOPLIST = [
        'busy', 'blocked', 'private', 'confidential', 'unavailable', '(no title)',
    ];

    /** @param  RawCalendarItem[]  $items */
    public function classify(array $items): FeedMode
    {
        if (count($items) === 0) {
            // No signal to classify from — don't fight the owner with a
            // confident-sounding guess; auto mode should just leave
            // full-detail matching available in case items show up later.
            return FeedMode::FullDetail;
        }

        $genericCount = 0;

        foreach ($items as $item) {
            if ($this->isGeneric($item)) {
                $genericCount++;
            }
        }

        if ($genericCount === 0) {
            return FeedMode::FullDetail;
        }

        if ($genericCount === count($items)) {
            return FeedMode::FreeBusyOnly;
        }

        return FeedMode::Mixed;
    }

    public function isGeneric(RawCalendarItem $item): bool
    {
        if ($item->componentType === 'VFREEBUSY') {
            return true;
        }

        $summary = $item->summary !== null ? mb_strtolower(trim($item->summary)) : '';

        return $summary === '' || in_array($summary, self::GENERIC_SUMMARY_STOPLIST, true);
    }
}
