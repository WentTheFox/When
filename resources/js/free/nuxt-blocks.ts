/**
 * Ported verbatim from WentTheNuxt's app/utils/free-blocks.ts — the source
 * app's own block-tiling algorithm, kept as close to the original as
 * possible (see resources/js/free/CalendarView.vue's header comment for
 * why: this is a deliberate "match the reference exactly" port, not a
 * reimplementation). Consumes App\Domain\Calendar\AvailabilityResult's
 * four-array shape directly (free/highlighted/unavailable/sleep can
 * legitimately overlap — see that class's PHP doc comment) and does all
 * overlap/precedence resolution client-side, exactly like the source app.
 */
import { format, parseISO } from 'date-fns';
import { TZDate } from '@date-fns/tz';
import { huFromSuffix } from './hu-time-suffix';

export interface FreeSlot {
  start: string;
  end: string;
}

export interface TentativeSlot extends FreeSlot {
  tentative?: boolean;
}

export interface HighlightedSlot extends TentativeSlot {
  activity?: string | null;
  visiting?: boolean;
  hosting?: boolean;
  /** Every configured highlight word that matched — a clause can name more than one person (e.g. "with Alice, Bob"). */
  highlight_words?: string[];
}

export interface AvailabilityResponse {
  free: FreeSlot[];
  highlighted: HighlightedSlot[];
  unavailable: TentativeSlot[];
  sleep: FreeSlot[];
}

export interface DayBlock {
  topPct: number;
  heightPct: number;
  startTime: string;
  endTime: string;
  type: 'free' | 'unavailable' | 'highlighted' | 'sleep';
  tentative?: boolean;
  activity?: string | null;
  visiting?: boolean;
  hosting?: boolean;
  highlightWords?: string[];
}

export function tildeTime(time: string, tentative?: boolean): string {
  return tentative ? `~${time}` : time;
}

function durationMinutes(startTime: string, endTime: string): { hours: number; minutes: number } {
  const [startHours, startMinutes] = startTime.split(':').map(Number);
  const [endHours, endMinutes] = endTime.split(':').map(Number);
  let totalMinutes = (endHours! * 60 + endMinutes!) - (startHours! * 60 + startMinutes!);
  if (totalMinutes < 0) totalMinutes += 24 * 60; // end wraps past midnight

  return { hours: Math.floor(totalMinutes / 60), minutes: totalMinutes % 60 };
}

// "4h"/"3h40m" (en) or "4ó"/"3ó20p" (hu) for the duration between two "HH:mm" times.
export function formatReservedDuration(startTime: string, endTime: string, locale: string): string {
  const { hours, minutes } = durationMinutes(startTime, endTime);

  if (locale === 'hu') {
    return minutes === 0 ? `${hours}ó` : `${hours}ó${minutes}p`;
  }
  return minutes === 0 ? `${hours}h` : `${hours}h${minutes}m`;
}

// "From ~17:00"/"From ~17:30" (en); "~17 órától" (hu, "óra" is always back-vowel
// so the hour-only form is always "-tól") or "~17:30-tól"/"~17:30-től" (hu, minutes
// present — the suffix attaches directly to the minute number, see hu-time-suffix.ts).
export function formatTentativeStart(startTime: string, locale: string): string {
  if (locale === 'hu') {
    const minutes = Number(startTime.split(':')[1]);
    return minutes === 0
      ? `~${Number(startTime.split(':')[0])} órától`
      : `~${startTime}-${huFromSuffix(minutes)}`;
  }
  return `From ~${startTime}`;
}

// Sleep ranges are inferred rather than confirmed, so they always get the
// tentative fade/dashed-border treatment, even though the API never marks
// them `tentative` (that flag only exists on highlighted/unavailable slots).
export function isTentativeDisplay(block: DayBlock): boolean {
  return !!block.tentative || block.type === 'sleep';
}

export function pctToTime(pct: number): string {
  const totalMinutes = Math.round((pct / 100) * 1440);
  const h = Math.floor(totalMinutes / 60) % 24;
  const m = totalMinutes % 60;
  return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
}

function formatSlotEndTime(end: Date): string {
  if (end.getHours() === 23 && end.getMinutes() === 59) return '00:00';
  return format(end, 'HH:mm');
}

function slotsToBlocks(
  slots: HighlightedSlot[],
  type: DayBlock['type'],
  dayStartTs: number,
  dayEndTs: number,
  dayMs: number,
  timezone: string,
): DayBlock[] {
  return slots.flatMap((slot): DayBlock[] => {
    const slotStart = parseISO(slot.start);
    const slotEnd = parseISO(slot.end);

    if (slotEnd.getTime() <= dayStartTs || slotStart.getTime() > dayEndTs) return [];

    const effStartTs = Math.max(slotStart.getTime(), dayStartTs);
    const effEndTs = Math.min(slotEnd.getTime(), dayEndTs);

    if (effEndTs <= effStartTs) return [];

    const startMs = effStartTs - dayStartTs;
    const endMs = effEndTs - dayStartTs;
    const slotStartInTz = new TZDate(slotStart, timezone);
    const slotEndInTz = new TZDate(slotEnd, timezone);

    return [{
      topPct: (startMs / dayMs) * 100,
      heightPct: ((endMs - startMs) / dayMs) * 100,
      startTime: effStartTs > slotStart.getTime() ? '00:00' : format(slotStartInTz, 'HH:mm'),
      endTime: effEndTs < slotEnd.getTime() ? '00:00' : formatSlotEndTime(slotEndInTz),
      type,
      tentative: slot.tentative,
      activity: slot.activity,
      visiting: slot.visiting,
      hosting: slot.hosting,
      highlightWords: slot.highlight_words,
    }];
  });
}

// The API can return overlapping/redundant ranges for the same slot type (e.g. a
// busy block that's a superset of two narrower busy blocks). Coalesce those before
// rendering so they don't show up as duplicate blocks/rows. Blocks are only ever
// merged within the same type+tentative group: e.g. a non-tentative "unavailable"
// range can fully contain a tentative one (the tentative sub-range duplicates a
// `highlighted` event so it can be carved out elsewhere) — merging across that
// boundary would wrongly mark the whole confirmed-busy span as tentative.
function mergeOverlappingBlocks(blocks: DayBlock[]): DayBlock[] {
  if (blocks.length <= 1) return blocks;

  const groups = new Map<string, DayBlock[]>();
  for (const block of blocks) {
    const key = `${block.type}|${block.tentative ? '1' : '0'}|${block.activity ?? ''}|${(block.highlightWords ?? []).join(',')}`;
    const group = groups.get(key);
    if (group) group.push(block);
    else groups.set(key, [block]);
  }

  const result: DayBlock[] = [];
  for (const group of groups.values()) {
    const sorted = group.sort((a, b) => a.topPct - b.topPct);
    let current = sorted[0]!;

    for (let i = 1; i < sorted.length; i++) {
      const next = sorted[i]!;
      const currentEnd = current.topPct + current.heightPct;

      if (next.topPct > currentEnd + 0.001) {
        result.push(current);
        current = next;
        continue;
      }

      const nextEnd = next.topPct + next.heightPct;
      if (nextEnd > currentEnd) {
        current = { ...current, heightPct: nextEnd - current.topPct, endTime: next.endTime };
      }
    }

    result.push(current);
  }

  return result;
}

// Carves `overlay` blocks (highlighted events) out of a `base` block (free or
// unavailable time), splitting it around each overlap so the overlay renders on top.
function splitByOverlay(base: DayBlock, overlay: DayBlock[]): DayBlock[] {
  const baseEnd = base.topPct + base.heightPct;
  const overlapping = overlay
    .filter(o => o.topPct < baseEnd && o.topPct + o.heightPct > base.topPct)
    .sort((a, b) => a.topPct - b.topPct);

  if (overlapping.length === 0) return [base];

  const result: DayBlock[] = [];
  let cursor = base.topPct;

  for (const o of overlapping) {
    const oEnd = o.topPct + o.heightPct;
    const clippedStart = Math.max(o.topPct, base.topPct);
    const clippedEnd = Math.min(oEnd, baseEnd);

    if (clippedStart > cursor) {
      result.push({ ...base, topPct: cursor, heightPct: clippedStart - cursor, startTime: pctToTime(cursor), endTime: pctToTime(clippedStart) });
    }
    result.push({ ...o, topPct: clippedStart, heightPct: clippedEnd - clippedStart });
    cursor = clippedEnd;
  }

  if (baseEnd - cursor > 0.001) {
    result.push({ ...base, topPct: cursor, heightPct: baseEnd - cursor, startTime: pctToTime(cursor), endTime: pctToTime(baseEnd) });
  }

  return result;
}

export function getBlocksForDay(
  day: Date,
  freeSlots: FreeSlot[],
  highlightedSlots: HighlightedSlot[],
  unavailableSlots: TentativeSlot[],
  sleepSlots: FreeSlot[],
  timezone: string,
): DayBlock[] {
  const tzDay = new TZDate(day, timezone);
  const y = tzDay.getFullYear();
  const mo = tzDay.getMonth();
  const da = tzDay.getDate();

  const dayStart = new TZDate(y, mo, da, 0, 0, 0, 0, timezone);
  const dayEnd = new TZDate(y, mo, da, 23, 59, 59, 999, timezone);
  const dayMs = dayEnd.getTime() - dayStart.getTime() + 1;
  const dayStartTs = dayStart.getTime();
  const dayEndTs = dayEnd.getTime();

  const freeBlocks = mergeOverlappingBlocks(slotsToBlocks(freeSlots, 'free', dayStartTs, dayEndTs, dayMs, timezone));
  const unavailableBlocks = mergeOverlappingBlocks(slotsToBlocks(unavailableSlots, 'unavailable', dayStartTs, dayEndTs, dayMs, timezone));
  const highlightedBlocks = mergeOverlappingBlocks(slotsToBlocks(highlightedSlots, 'highlighted', dayStartTs, dayEndTs, dayMs, timezone));
  // Sleep ranges fill a gap that's absent from both `free` and `unavailable` rather
  // than overlapping either, so they're their own top-level blocks, not an overlay.
  const sleepBlocks = mergeOverlappingBlocks(slotsToBlocks(sleepSlots, 'sleep', dayStartTs, dayEndTs, dayMs, timezone));

  const splitBlocks = highlightedBlocks.length > 0
    ? [...unavailableBlocks, ...freeBlocks].flatMap(b => splitByOverlay(b, highlightedBlocks))
    : [...unavailableBlocks, ...freeBlocks];
  // Splitting can itself produce duplicate fragments (e.g. an `unavailable` entry
  // whose range exactly matches the highlighted overlay collapses entirely into
  // a copy of that overlay), so merge once more after splitting.
  const baseBlocks = mergeOverlappingBlocks(splitBlocks);

  return [...baseBlocks, ...sleepBlocks].sort((a, b) => a.topPct - b.topPct);
}
