import { describe, expect, it } from 'vitest';
import { getBlocksForDay, hasAnyFreeTimeInRange, type AvailabilitySlot } from '../blocks';

describe('getBlocksForDay', () => {
  it('fills the whole day with a single free block when there are no slots', () => {
    const day = new Date(2026, 5, 3); // local time, June 3 2026
    const blocks = getBlocksForDay(day, []);

    expect(blocks).toHaveLength(1);
    expect(blocks[0].type).toBe('free');
    expect(blocks[0].startTime).toBe('00:00');
    expect(blocks[0].endTime).toBe('00:00'); // end-of-day wraps to midnight
    expect(blocks[0].topPct).toBeCloseTo(0, 5);
    expect(blocks[0].heightPct).toBeCloseTo(100, 1);
  });

  it('fills gaps around a single busy slot with free blocks', () => {
    const day = new Date(2026, 5, 3);
    const slots: AvailabilitySlot[] = [
      { type: 'busy', start: '2026-06-03T09:00:00', end: '2026-06-03T10:00:00', highlight_word: null },
    ];

    const blocks = getBlocksForDay(day, slots);

    expect(blocks.map((b) => b.type)).toEqual(['free', 'busy', 'free']);
    expect(blocks[1].startTime).toBe('09:00');
    expect(blocks[1].endTime).toBe('10:00');
  });

  it('carries the highlight word through to the block', () => {
    const day = new Date(2026, 5, 3);
    const slots: AvailabilitySlot[] = [
      { type: 'highlighted', start: '2026-06-03T09:00:00', end: '2026-06-03T10:00:00', highlight_word: 'Alice' },
    ];

    const blocks = getBlocksForDay(day, slots);
    const highlighted = blocks.find((b) => b.type === 'highlighted');

    expect(highlighted?.highlightWord).toBe('Alice');
  });

  it('clips a slot spanning multiple days to just this day', () => {
    const day = new Date(2026, 5, 3);
    const slots: AvailabilitySlot[] = [
      { type: 'sleep', start: '2026-06-02T23:00:00', end: '2026-06-03T07:00:00', highlight_word: null },
    ];

    const blocks = getBlocksForDay(day, slots);
    const sleep = blocks.find((b) => b.type === 'sleep');

    expect(sleep?.startTime).toBe('00:00');
    expect(sleep?.endTime).toBe('07:00');
  });

  it('ignores slots entirely outside the requested day', () => {
    const day = new Date(2026, 5, 3);
    const slots: AvailabilitySlot[] = [
      { type: 'busy', start: '2026-06-05T09:00:00', end: '2026-06-05T10:00:00', highlight_word: null },
    ];

    const blocks = getBlocksForDay(day, slots);

    expect(blocks).toHaveLength(1);
    expect(blocks[0].type).toBe('free');
  });

  it('handles back-to-back slots with no free gap between them', () => {
    const day = new Date(2026, 5, 3);
    const slots: AvailabilitySlot[] = [
      { type: 'busy', start: '2026-06-03T09:00:00', end: '2026-06-03T10:00:00', highlight_word: null },
      { type: 'highlighted', start: '2026-06-03T10:00:00', end: '2026-06-03T11:00:00', highlight_word: 'Bob' },
    ];

    const blocks = getBlocksForDay(day, slots);

    expect(blocks.map((b) => b.type)).toEqual(['free', 'busy', 'highlighted', 'free']);
  });
});

describe('hasAnyFreeTimeInRange', () => {
  it('is true when a day in range has no slots at all', () => {
    const rangeStart = new Date(2026, 5, 3);
    const rangeEnd = new Date(2026, 5, 3);

    expect(hasAnyFreeTimeInRange([], rangeStart, rangeEnd)).toBe(true);
  });

  it('is false when a single day is entirely covered by a busy slot', () => {
    const rangeStart = new Date(2026, 5, 3);
    const rangeEnd = new Date(2026, 5, 3);
    const slots: AvailabilitySlot[] = [
      { type: 'busy', start: '2026-06-03T00:00:00', end: '2026-06-04T00:00:00', highlight_word: null },
    ];

    expect(hasAnyFreeTimeInRange(slots, rangeStart, rangeEnd)).toBe(false);
  });
});
