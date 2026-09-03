import type { Locale } from 'date-fns';
import {
  ar, cs, da, de, el, enUS, es, fi, fr, he, hi, hu, it, ja, ko, nb, nl, pl, pt, ro, ru, sk, sv, tr, uk, zhCN,
} from 'date-fns/locale';

/**
 * Maps App\Support\Locales::codes() to date-fns' own Locale objects for
 * CalendarView/AgendaView/MonthView's weekday/month-name formatting. Two
 * of our codes don't line up 1:1 with a date-fns locale name: 'no'
 * (Norwegian, our code — matches this app's own Locales::NAMES) maps to
 * date-fns' 'nb' (Bokmål, the only Norwegian variant it ships), and 'zh'
 * maps to date-fns' 'zhCN' (Simplified, the only Chinese variant it
 * ships).
 */
const DATE_FNS_LOCALES: Record<string, Locale> = {
  en: enUS, hu, de, fr, es, it, pt, nl, pl, ro, cs, sk, sv, da, no: nb, fi, el, tr, ru, uk, ja, zh: zhCN, ko, ar, he, hi,
};

export function resolveDateFnsLocale(code: string): Locale {
  return DATE_FNS_LOCALES[code] ?? enUS;
}
