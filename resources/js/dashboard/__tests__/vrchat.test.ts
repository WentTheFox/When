import { describe, expect, it } from 'vitest';
import { formatDuration } from '../vrchat';

describe('formatDuration', () => {
  it('shows nothing as 0 min and sub-minute time as such', () => {
    expect(formatDuration(0)).toBe('0 min');
    expect(formatDuration(-5)).toBe('0 min');
    expect(formatDuration(59_999)).toBe('under a minute');
  });

  it('uses whole minutes below an hour', () => {
    expect(formatDuration(60_000)).toBe('1 min');
    expect(formatDuration(59 * 60_000 + 59_000)).toBe('59 min');
  });

  it('adds hours, dropping a zero remainder', () => {
    expect(formatDuration(60 * 60_000)).toBe('1 h');
    expect(formatDuration(185 * 60_000)).toBe('3 h 5 min');
  });
});
