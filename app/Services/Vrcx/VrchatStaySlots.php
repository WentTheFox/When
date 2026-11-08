<?php

namespace App\Services\Vrcx;

use App\Domain\Calendar\AvailabilitySlot;
use App\Models\Connection;
use App\Support\IconKey;
use Carbon\CarbonImmutable;

/**
 * A connection's VRChat time together (ConnectionVrchatStay, §0.2 tier,
 * APP_KEY-decrypted transiently here) as intervals clipped to a range. Only
 * ever the given connection's own stays: callers pass a share link's own
 * connection, so one person's VR time never lands on someone else's link.
 */
class VrchatStaySlots
{
    /** The name shown for VR time together on the free page. */
    public const ACTIVITY = 'VRChat';

    /** @return list<array{start: CarbonImmutable, end: CarbonImmutable}> */
    public function intervals(?Connection $connection, CarbonImmutable $rangeStart, CarbonImmutable $rangeEnd): array
    {
        if ($connection === null) {
            return [];
        }

        $slots = [];

        foreach ($connection->vrchatStays as $stay) {
            try {
                $start = CarbonImmutable::parse((string) ($stay->payload['start'] ?? ''));
                $end = CarbonImmutable::parse((string) ($stay->payload['end'] ?? ''));
            } catch (\Throwable) {
                continue;
            }

            if ($end->gt($rangeStart) && $start->lt($rangeEnd) && $end->gt($start)) {
                $slots[] = ['start' => $start->max($rangeStart), 'end' => $end->min($rangeEnd)];
            }
        }

        return $slots;
    }

    /**
     * The same intervals as highlighted slots named "VRChat", for the free page.
     *
     * @return list<AvailabilitySlot>
     */
    public function highlightedSlots(?Connection $connection, CarbonImmutable $rangeStart, CarbonImmutable $rangeEnd): array
    {
        return array_map(fn (array $i) => new AvailabilitySlot(
            start: $i['start'],
            end: $i['end'],
            type: 'highlighted',
            activity: self::ACTIVITY,
            activityIcon: IconKey::VR->value,
        ), $this->intervals($connection, $rangeStart, $rangeEnd));
    }
}
