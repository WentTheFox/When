import { trans } from 'laravel-vue-i18n';

export interface Recurrence {
  unit: 'day' | 'week' | 'month' | 'year';
  interval: number;
}

/** "Every week" / "Every 2 weeks", localized — see lang/*.json's free.recurrence.* and free.recurrenceEvery.*. */
export function recurrenceText(recurrence: Recurrence): string {
  return recurrence.interval > 1
    ? trans(`free.recurrenceEvery.${recurrence.unit}`, { count: String(recurrence.interval) })
    : trans(`free.recurrence.${recurrence.unit}`);
}
