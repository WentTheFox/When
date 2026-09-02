<?php

namespace App\Domain\Calendar;

/**
 * The API's actual wire shape (§5.1, matching the source app's own
 * AvailabilityController/TimeSlot contract): five categorized arrays that
 * can legitimately overlap each other — an event that's both busy AND
 * matches a highlight word appears in both `unavailable` and `highlighted`
 * for the same span, and `free`/`sleep` windows are computed independently
 * per-day rather than as "whatever's left over." `work` is the same kind of
 * overlay as `highlighted`: an event matching the owner's work-event
 * pattern still counts as ordinary busy time (present in `unavailable`
 * too), just additionally tagged so the calendar can render it as its own
 * category — not a fifth mutually-exclusive bucket. Reconciling those
 * overlaps into a single non-overlapping visual layout (carving the
 * highlighted/work portions out of unavailable/free, letting a tentative
 * slot's fade blend into its rendered neighbor) is deliberately a
 * *client-side* concern — see resources/js/free/nuxt-blocks.ts's
 * getBlocksForDay, ported from the same source app — not something this
 * class or AvailabilityService resolves before sending it out.
 */
final class AvailabilityResult
{
    /**
     * @param  AvailabilitySlot[]  $free
     * @param  AvailabilitySlot[]  $highlighted
     * @param  AvailabilitySlot[]  $unavailable
     * @param  AvailabilitySlot[]  $work
     * @param  AvailabilitySlot[]  $sleep
     */
    public function __construct(
        public readonly array $free,
        public readonly array $highlighted,
        public readonly array $unavailable,
        public readonly array $work,
        public readonly array $sleep,
    ) {}

    public function toArray(): array
    {
        return [
            'free' => array_map(fn (AvailabilitySlot $s) => $s->toArray(), $this->free),
            'highlighted' => array_map(fn (AvailabilitySlot $s) => $s->toArray(), $this->highlighted),
            'unavailable' => array_map(fn (AvailabilitySlot $s) => $s->toArray(), $this->unavailable),
            'work' => array_map(fn (AvailabilitySlot $s) => $s->toArray(), $this->work),
            'sleep' => array_map(fn (AvailabilitySlot $s) => $s->toArray(), $this->sleep),
        ];
    }
}
