/**
 * Turns a decrypted list of {@link AvailabilitySlot}s (the server's
 * "everything outside computed slots is implicitly free" format — see
 * App\Domain\Calendar\AvailabilitySlot::toArray()) into per-day render
 * blocks, filling the free gaps client-side. Unlike the source app, the
 * backend already guarantees no overlapping slots reach the client (its
 * own precedence-resolution pass handles that — see
 * AvailabilityService::resolveOverlapsByPrecedence), so this only needs to
 * sort and fill gaps, not merge/split overlays.
 *
 * Always renders in the viewer's own browser-local timezone (plain Date
 * arithmetic) — this app doesn't offer viewing in an arbitrary timezone.
 */

export type SlotType = 'sleep' | 'busy' | 'highlighted' | 'tentative';
export type DayBlockType = SlotType | 'free';

export interface AvailabilitySlot {
  type: SlotType;
  start: string;
  end: string;
  highlight_word: string | null;
}

export interface DayBlock {
  topPct: number;
  heightPct: number;
  startTime: string;
  endTime: string;
  type: DayBlockType;
  highlightWord: string | null;
}

interface Interval {
  start: number;
  end: number;
  type: DayBlockType;
  highlightWord: string | null;
}

export function startOfDay(d: Date): Date {
  const r = new Date(d);
  r.setHours(0, 0, 0, 0);
  return r;
}

export function endOfDay(d: Date): Date {
  const r = new Date(d);
  r.setHours(23, 59, 59, 999);
  return r;
}

function formatTime(d: Date): string {
  return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
}

export function getBlocksForDay(day: Date, slots: AvailabilitySlot[]): DayBlock[] {
  const dayStart = startOfDay(day);
  const dayEnd = endOfDay(day);
  const dayStartTs = dayStart.getTime();
  const dayEndTs = dayEnd.getTime();
  const dayMs = dayEndTs - dayStartTs + 1;

  const clipped: Interval[] = [];

  for (const slot of slots) {
    const start = new Date(slot.start).getTime();
    const end = new Date(slot.end).getTime();

    if (end <= dayStartTs || start > dayEndTs) continue;

    const effStart = Math.max(start, dayStartTs);
    const effEnd = Math.min(end, dayEndTs + 1);

    if (effEnd <= effStart) continue;

    clipped.push({ start: effStart, end: effEnd, type: slot.type, highlightWord: slot.highlight_word });
  }

  clipped.sort((a, b) => a.start - b.start);

  const withFree: Interval[] = [];
  let cursor = dayStartTs;

  for (const interval of clipped) {
    if (interval.start > cursor) {
      withFree.push({ start: cursor, end: interval.start, type: 'free', highlightWord: null });
    }
    withFree.push(interval);
    cursor = Math.max(cursor, interval.end);
  }

  if (cursor < dayEndTs + 1) {
    withFree.push({ start: cursor, end: dayEndTs + 1, type: 'free', highlightWord: null });
  }

  return withFree.map((interval) => {
    const endDate = new Date(interval.end);

    return {
      topPct: ((interval.start - dayStartTs) / dayMs) * 100,
      heightPct: ((interval.end - interval.start) / dayMs) * 100,
      startTime: formatTime(new Date(interval.start)),
      endTime: interval.end > dayEndTs ? '00:00' : formatTime(endDate),
      type: interval.type,
      highlightWord: interval.highlightWord,
    };
  });
}

export function hasAnyFreeTimeInRange(slots: AvailabilitySlot[], rangeStart: Date, rangeEnd: Date): boolean {
  // "Free" isn't in the slot list at all — presence of free time in a range
  // means the union of non-free slots doesn't fully cover it, which is
  // easiest to check by just asking whether every day in the range has at
  // least one 'free' block once gaps are filled.
  const day = startOfDay(rangeStart);
  const end = rangeEnd.getTime();

  for (let cursor = day.getTime(); cursor <= end; cursor += 24 * 60 * 60 * 1000) {
    const blocks = getBlocksForDay(new Date(cursor), slots);
    if (blocks.some((b) => b.type === 'free')) return true;
  }

  return false;
}
