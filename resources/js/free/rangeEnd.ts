import { format } from 'date-fns';
import { TZDate } from '@date-fns/tz';

/**
 * True when `day` falls on or after the first day the server did NOT compute
 * (the computed range's end). Compared as owner-timezone calendar dates so a
 * viewer in another zone sees the same cutoff day the owner's range used.
 * Such days are *hidden* (never computed), which is not the same as empty.
 */
export function isBeyondRange(day: Date, hiddenFrom: Date | null, timezone: string): boolean {
  if (hiddenFrom === null) return false;
  const fmt = 'yyyy-MM-dd';
  return format(new TZDate(day, timezone), fmt) >= format(new TZDate(hiddenFrom, timezone), fmt);
}
