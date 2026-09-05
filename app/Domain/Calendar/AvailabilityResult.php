<?php

namespace App\Domain\Calendar;

/**
 * The API's actual wire shape (§5.1): one flat, possibly-overlapping list
 * of tagged events rather than several parallel keyed arrays — an event
 * that's both busy AND matches a highlight word produces two entries for
 * the same span, one `unavailable` and one `highlighted`, and `free`/
 * `sleep` entries are computed independently per-day rather than as
 * "whatever's left over." `work`/`school`/`public` are the same kind of
 * overlay as `highlighted`: an event matching one of those patterns still
 * produces an `unavailable` entry too, just an additional tagged one so the
 * calendar can render it as its own category — not a mutually-exclusive
 * bucket. Reconciling those overlaps into a single non-overlapping visual
 * layout (carving the highlighted/work/school/public portions out of
 * unavailable/free, letting a tentative slot's fade blend into its
 * rendered neighbor) is deliberately a *client-side* concern — see
 * resources/js/free/nuxt-blocks.ts's getBlocksForDay — not something this
 * class or AvailabilityService resolves before sending it out.
 */
final class AvailabilityResult
{
    /** @param  AvailabilitySlot[]  $events */
    public function __construct(
        public readonly array $events,
    ) {}

    public function toArray(): array
    {
        return array_map(fn (AvailabilitySlot $s) => $s->toArray(), $this->events);
    }
}
