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
  tentative_start?: boolean;
  tentative_end?: boolean;
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
  work: TentativeSlot[];
  sleep: FreeSlot[];
}

export interface DayBlock {
  topPct: number;
  heightPct: number;
  startTime: string;
  endTime: string;
  type: 'free' | 'unavailable' | 'highlighted' | 'work' | 'sleep';
  tentativeStart?: boolean;
  tentativeEnd?: boolean;
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

// "From ~17:00"/"From 17:00" (en); "~17 órától"/"17 órától" (hu, "óra" is always
// back-vowel so the hour-only form is always "-tól") or "~17:30-tól"/"17:30-től" (hu,
// minutes present — the suffix attaches directly to the minute number, see
// hu-time-suffix.ts). `tentative` controls only the "~" mark: a fully-tentative
// event's start is itself unknown (tilde-marked), while a fixed-start/open-end
// event's start is the known edge, shown plain — see formatFromTime below.
function formatFromTimeCore(startTime: string, locale: string, tentative: boolean): string {
  const mark = tentative ? '~' : '';
  if (locale === 'hu') {
    const minutes = Number(startTime.split(':')[1]);
    return minutes === 0
      ? `${mark}${Number(startTime.split(':')[0])} órától`
      : `${mark}${startTime}-${huFromSuffix(minutes)}`;
  }
  return `From ${mark}${startTime}`;
}

export function formatTentativeStart(startTime: string, locale: string): string {
  return formatFromTimeCore(startTime, locale, true);
}

// The known-start side of a fixed-start/open-end event: "From 17:00" (en) /
// "17 órától"/"17:30-tól" (hu) — same wording as formatTentativeStart, just
// never tilde-marked since this time is the confirmed edge, not the fuzzy one.
export function formatFromTime(startTime: string, locale: string): string {
  return formatFromTimeCore(startTime, locale, false);
}

// The known-end side of an open-start/fixed-end event: "Until 19:00" (en) /
// "19 óráig"/"19:30-ig" (hu — "-ig" is vowel-harmony-invariant, unlike
// "-tól/-től", so no hu-time-suffix lookup is needed for the digital form;
// the round-hour spelled form takes a linking "á" before "-ig", same as
// "-tól" attaches to "óra" as "órától" in formatFromTimeCore). Never
// tilde-marked, same reasoning as formatFromTime.
export function formatUntilTime(endTime: string, locale: string): string {
  if (locale === 'hu') {
    const minutes = Number(endTime.split(':')[1]);
    return minutes === 0 ? `${Number(endTime.split(':')[0])} óráig` : `${endTime}-ig`;
  }
  return `Until ${endTime}`;
}

// Sleep ranges are inferred rather than confirmed, so they always get the
// tentative fade/dashed-border treatment on both edges, even though the API
// never marks them tentative (those flags only exist on highlighted/
// unavailable slots).
export function isTentativeStartDisplay(block: DayBlock): boolean {
  return !!block.tentativeStart || block.type === 'sleep';
}

export function isTentativeEndDisplay(block: DayBlock): boolean {
  return !!block.tentativeEnd || block.type === 'sleep';
}

// Same tentative-ness the fade/dashed-border styling above reacts to, minus
// the sleep-always-fuzzy rule — sleep's dashed edges are a visual "this is
// inferred, not confirmed" cue only (AvailabilitySlot never actually sets
// tentativeStart/tentativeEnd for a sleep slot), so appending the literal
// "(tentative)" text suffix to every sleep block read as a data claim the
// API was never making. Text suffix uses the raw per-slot flags directly.
export function isTentativeSuffixShown(block: DayBlock): boolean {
  return !!block.tentativeStart || !!block.tentativeEnd;
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
      tentativeStart: slot.tentative_start,
      tentativeEnd: slot.tentative_end,
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
// merged within the same type+tentativeStart+tentativeEnd group: e.g. a confirmed
// "unavailable" range can fully contain a fully-tentative one (the tentative
// sub-range duplicates a `highlighted` event so it can be carved out elsewhere) —
// merging across that boundary would wrongly mark the whole confirmed-busy span as
// tentative, and an open-start block should never silently absorb an open-end one.
function mergeOverlappingBlocks(blocks: DayBlock[]): DayBlock[] {
  if (blocks.length <= 1) return blocks;

  const groups = new Map<string, DayBlock[]>();
  for (const block of blocks) {
    const key = `${block.type}|${block.tentativeStart ? '1' : '0'}${block.tentativeEnd ? '1' : '0'}|${block.activity ?? ''}|${(block.highlightWords ?? []).join(',')}`;
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
  workSlots: TentativeSlot[] = [],
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
  const workBlocks = mergeOverlappingBlocks(slotsToBlocks(workSlots, 'work', dayStartTs, dayEndTs, dayMs, timezone));
  // Sleep ranges fill a gap that's absent from both `free` and `unavailable` rather
  // than overlapping either, so they're their own top-level blocks, not an overlay.
  const sleepBlocks = mergeOverlappingBlocks(slotsToBlocks(sleepSlots, 'sleep', dayStartTs, dayEndTs, dayMs, timezone));

  const splitBlocks = highlightedBlocks.length > 0
    ? [...unavailableBlocks, ...freeBlocks].flatMap(b => splitByOverlay(b, highlightedBlocks))
    : [...unavailableBlocks, ...freeBlocks];
  // Splitting can itself produce duplicate fragments (e.g. an `unavailable` entry
  // whose range exactly matches the highlighted overlay collapses entirely into
  // a copy of that overlay), so merge once more after splitting.
  let baseBlocks = mergeOverlappingBlocks(splitBlocks);

  // Work is a second, lower-priority overlay on top of whatever's left after
  // highlighted has already carved out its own portion — an already-
  // `highlighted` fragment is left alone rather than re-split (a highlighted
  // person's event stays highlighted even during the owner's own work
  // hours; work only ever claims plain unavailable/free time).
  if (workBlocks.length > 0) {
    baseBlocks = mergeOverlappingBlocks(
      baseBlocks.flatMap(b => (b.type === 'highlighted' ? [b] : splitByOverlay(b, workBlocks))),
    );
  }

  return [...baseBlocks, ...sleepBlocks].sort((a, b) => a.topPct - b.topPct);
}
