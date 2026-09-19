import { describe, expect, it } from 'vitest';
import { getBlocksForDay, type EventSlot } from '../nuxt-blocks';

const TZ = 'UTC';
const day = new Date('2026-03-10T12:00:00Z');

function slot(type: EventSlot['type'], start: string, end: string): EventSlot {
  return { type, start: `2026-03-10T${start}:00Z`, end: `2026-03-10T${end}:00Z` } as EventSlot;
}

describe('getBlocksForDay', () => {
  it('renders a highlighted event on top of an overlapping sleep block', () => {
    const blocks = getBlocksForDay(day, [
      slot('sleep', '00:00', '08:00'),
      slot('highlighted', '06:00', '10:00'),
      slot('free', '08:00', '12:00'),
    ], TZ);

    const sleep = blocks.filter(b => b.type === 'sleep');
    const highlighted = blocks.filter(b => b.type === 'highlighted');

    expect(sleep).toHaveLength(1);
    expect(sleep[0]!.endTime).toBe('06:00');
    expect(highlighted).toHaveLength(1);
    expect(highlighted[0]!.topPct).toBeCloseTo((6 / 24) * 100);
    expect(highlighted[0]!.heightPct).toBeCloseTo((4 / 24) * 100);
  });

  it('splits sleep around a highlighted event fully inside it', () => {
    const blocks = getBlocksForDay(day, [
      slot('sleep', '00:00', '08:00'),
      slot('highlighted', '02:00', '03:00'),
    ], TZ);

    expect(blocks.map(b => b.type)).toEqual(['sleep', 'highlighted', 'sleep']);
  });

  it('does not let non-highlighted overlays claim sleep', () => {
    const blocks = getBlocksForDay(day, [
      slot('sleep', '00:00', '08:00'),
      slot('work', '06:00', '10:00'),
    ], TZ);

    expect(blocks.map(b => b.type)).toEqual(['sleep']);
  });
});
