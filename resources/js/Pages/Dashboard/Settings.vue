<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome';
import { faEye, faEyeSlash, faMoon, faSun } from '@fortawesome/free-solid-svg-icons';
import {
  BAlert,
  BBadge,
  BButton,
  BCard,
  BFormGroup,
  BFormInput,
  BFormSelect,
  BFormTextarea,
  BInputGroup,
  BTooltip,
} from 'bootstrap-vue-next';
import { addDays as addDaysFns, startOfWeek as startOfWeekFns } from 'date-fns';
import { computed, onUnmounted, ref, watch } from 'vue';
import DashboardLayout from '../../Layouts/DashboardLayout.vue';
import { useLiveThemePreview } from '../../dashboard/liveThemePreview';
import PatternPreview from '../../dashboard/PatternPreview.vue';
import RegexHighlightedCode from '../../dashboard/RegexHighlightedCode.vue';
import RegexPatternInput from '../../dashboard/RegexPatternInput.vue';
import SleepExceptions from '../../dashboard/SleepExceptions.vue';
import CalendarView from '../../free/CalendarView.vue';
import { BLOCK_ALPHA, hexToRgba, hexToRgbTriplet, yiqTextColor } from '../../free/color-utils';
import { getColorPalette, getDefaultSwatchKey, resolveSwatchHex } from '../../free/color-palette';
import type { ColorSlot } from '../../free/color-palette';
import { faIconFor, getDefaultIconKey, getIconPalette, resolveIcon } from '../../free/icon-palette';
import type { IconSlot } from '../../free/icon-palette';
import { getDefaultNowColorKey, getNowColorPresets, resolveNowColorHex } from '../../free/now-color-presets';
import { useResolvedTheme } from '../../composables/useTheme';
import type { AvailabilityResponse } from '../../free/nuxt-blocks';
import type { SharedPageProps } from '../../sharedPageProps';

defineOptions({ layout: DashboardLayout });

interface Settings {
  timezone: string;
  /** 0=Sunday..6=Saturday, date-fns' own weekStartsOn convention. */
  week_start: number;
  dnd_event_pattern: string | null;
  nap_event_pattern: string | null;
  work_event_pattern: string | null;
  calendar_parsing_mode: 'full_detail' | 'free_busy_only';
  highlight_clause_pattern: string | null;
  highlight_split_pattern: string | null;
  activity_clause_pattern: string | null;
  tentative_pattern: string | null;
  open_end_pattern: string | null;
  open_start_pattern: string | null;
  public_page_title_en: string | null;
  public_page_title_hu: string | null;
  name: string;
  accent_color_key: string | null;
  secondary_color_key: string | null;
  sleep_color_key: string | null;
  busy_color_key: string | null;
  work_color_key: string | null;
  free_color_key: string | null;
  highlight_color_key: string | null;
  free_icon_key: string | null;
  busy_icon_key: string | null;
  work_icon_key: string | null;
  sleep_icon_key: string | null;
  highlight_icon_key: string | null;
  now_color_key: string | null;
  availability: Record<number, { wake: string | null; sleep: string | null }>;
}

const props = defineProps<{
  settings: Settings;
  defaults: {
    dndEventPattern: string;
    napEventPattern: string;
    workEventPattern: string;
    highlightClausePattern: string;
    highlightSplitPattern: string;
    activityClausePattern: string;
    tentativePattern: string;
    openEndPattern: string;
    openStartPattern: string;
  };
  timezones: string[];
  calendarUrl: string | null;
  sleepExceptions: { id: string; start_date: string; end_date: string; label_ciphertext: string | null }[];
}>();

const page = usePage<SharedPageProps>();

const days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
const weekStartOptions = days.map((label, value) => ({ value, label }));
/**
 * Each slot picks a swatch KEY from the app's fixed palette (see
 * color-palette.ts) rather than an arbitrary hex — a free-form picker let
 * an owner choose a color that read fine in whichever theme they were
 * previewing and badly in the other (e.g. a light pastel free-block color
 * picked in light mode nearly disappears against dark mode's own dark
 * background); every swatch instead has its own hand-picked light AND dark
 * hex. "Current time" isn't here — it picks from its own, separate
 * curated list (NowColorPresetKey via now-color-presets.ts), rendered
 * below via the same swatch-grid pattern.
 */
const colorFields: { field: keyof Settings; slot: ColorSlot; label: string }[] = [
  { field: 'accent_color_key', slot: 'accent', label: 'Accent' },
  { field: 'secondary_color_key', slot: 'secondary', label: 'Secondary' },
  { field: 'free_color_key', slot: 'free', label: 'Free' },
  { field: 'busy_color_key', slot: 'busy', label: 'Unavailable' },
  { field: 'work_color_key', slot: 'work', label: 'Work' },
  { field: 'sleep_color_key', slot: 'sleep', label: 'Sleep' },
  { field: 'highlight_color_key', slot: 'highlighted', label: 'Highlighted' },
];

const colorPalette = getColorPalette();

/**
 * Same curated-KEY-not-arbitrary-value idea as colorFields above, for the
 * icon each of the five block types renders on /free — see IconPalette's
 * own doc comment for why the actual FA icon lookup lives client-side
 * only. No accent/secondary equivalents: those aren't block types with
 * their own icon. `colorField` ties each icon slot back to that same
 * slot's own color-key field above — the selected icon in each group is
 * tinted with that group's own configured color (activeIconColor below),
 * not a single generic accent, so the icon picker visually matches the
 * color picker for the same block type right above it.
 */
const iconFields: { field: keyof Settings; slot: IconSlot; colorField: keyof Settings; label: string }[] = [
  { field: 'free_icon_key', slot: 'free', colorField: 'free_color_key', label: 'Free' },
  { field: 'busy_icon_key', slot: 'busy', colorField: 'busy_color_key', label: 'Unavailable' },
  { field: 'work_icon_key', slot: 'work', colorField: 'work_color_key', label: 'Work' },
  { field: 'sleep_icon_key', slot: 'sleep', colorField: 'sleep_color_key', label: 'Sleep' },
  { field: 'highlight_icon_key', slot: 'highlighted', colorField: 'highlight_color_key', label: 'Highlighted' },
];

const iconPalette = getIconPalette();

const nowColorPresets = getNowColorPresets();

/** Resolved against the settings page's own live theme — same as every other color-key resolution on this page (previewStyleLive etc.). */
function activeIconColor(iconField: (typeof iconFields)[number]): string {
  const colorKey = (form as unknown as Record<string, string>)[iconField.colorField];

  return resolveSwatchHex(colorKey, iconField.slot, resolvedTheme.value);
}

/**
 * One shared tooltip for every swatch across every color-slot group,
 * instead of a separate v-b-tooltip instance per circle — with ~21
 * swatches per group across 6 groups, that was well over a hundred
 * always-mounted floating-ui instances, and each one's own bubble sat in
 * the DOM regardless of visibility, easy to have overlap and steal
 * hover/hit-testing from a neighboring swatch. A single tooltip just
 * moves to whichever swatch is currently hovered/focused instead.
 */
const tooltipVisible = ref(false);
const activeSwatchTarget = ref<HTMLElement | null>(null);
const activeSwatchLabel = ref('');

function showSwatchTooltip(event: FocusEvent | MouseEvent, label: string): void {
  activeSwatchTarget.value = event.currentTarget as HTMLElement;
  activeSwatchLabel.value = label;
  tooltipVisible.value = true;
}

function hideSwatchTooltip(): void {
  tooltipVisible.value = false;
}

/**
 * A representative week of made-up events, fed into the exact same
 * CalendarView.vue Free/Show.vue uses for real API data — so this preview
 * is the actual calendar renderer against synthetic input, not a
 * reimplementation of what the real page looks like.
 */
const exampleDay = new Date();
// Fixed Monday-first anchor for the mock DATA (which weekday each made-up
// event falls on never changes) — kept separate from exampleVisibleDays
// below, which reorders these same 7 dates for DISPLAY according to the
// owner's chosen week_start, without moving the events themselves.
//
// Built as UTC midnight (Date.UTC), not local midnight + toISOString-style
// drift — CalendarView is fed :timezone="'UTC'" for this mock and reads
// each day via TZDate(day, 'UTC'), so a local-midnight Date in any
// non-UTC environment (e.g. local Monday 00:00 in UTC+2 is 22:00 Sunday
// UTC) would silently display one weekday early. Same root cause as the
// at()/atMinutes() fix above, just at the day-array level instead of the
// event-time level.
const exampleWeekDatesMonFirst = Array.from({ length: 7 }, (_, i) => {
  const dow = (exampleDay.getDay() + 6) % 7; // Monday = 0
  return new Date(Date.UTC(exampleDay.getFullYear(), exampleDay.getMonth(), exampleDay.getDate() - dow + i));
});
const exampleVisibleDays = computed(() => {
  const startIdx = exampleWeekDatesMonFirst.findIndex((d) => d.getUTCDay() === form.week_start);
  return Array.from({ length: 7 }, (_, i) => exampleWeekDatesMonFirst[(startIdx + i) % 7]!);
});
/**
 * The color-slot preview's own 3-day window (see colorPreviewVisibleDays
 * below) — yesterday/today/tomorrow, centered on the real current day
 * rather than whichever 3 days happen to fall first for the owner's
 * configured week_start. The color preview's whole point is judging how
 * the current-time indicator reads against a chosen color, so the visible
 * window has to actually contain today regardless of what day of the week
 * it is; a week_start-first slice would miss it entirely on, say, a
 * Thursday with week_start=Monday. The made-up events themselves stay
 * exactly where exampleWeekDatesMonFirst already pins them (Monday's
 * "Lunch with Alice" is always Monday's event) — only which 3 real
 * calendar days get displayed shifts here, not the events.
 */
const colorPreviewExampleDays = computed(() => {
  const today = exampleWeekDatesMonFirst[(exampleDay.getDay() + 6) % 7]!;
  return [-1, 0, 1].map((offset) => new Date(Date.UTC(today.getUTCFullYear(), today.getUTCMonth(), today.getUTCDate() + offset)));
});
/**
 * Live-updates as the Wake & sleep times table is edited (see
 * form.availability below), mirroring AvailabilityService's own
 * dayWindow()/computeSleepBlocks() logic client-side: a day contributes a
 * wake→sleep window only when *both* times are filled in for that day —
 * otherwise it's treated as fully awake all day, same as the real backend,
 * and no sleep entry appears for it at all (never a partial one from just
 * one side being set).
 */
const exampleAvailability = computed<AvailabilityResponse>(() => {
  // This mock is rendered with :timezone="'UTC'" below, so its timestamps
  // must be built as UTC directly (Date.UTC), not via the environment's own
  // local wall-clock setters + toISOString() — that combination silently
  // shifts every block by the runtime's own UTC offset (e.g. 12:00 local in
  // UTC+2 serializes to "10:00Z", which a UTC-timezone CalendarView then
  // renders as 10:00, two hours off from what was actually asked for).
  const base = exampleWeekDatesMonFirst[0]!;
  const at = (dayOffset: number, hours: number, minutes = 0) =>
    new Date(Date.UTC(base.getUTCFullYear(), base.getUTCMonth(), base.getUTCDate() + dayOffset, hours, minutes, 0, 0)).toISOString();
  const atAbsMinutes = (absoluteMinutes: number) => {
    const dayOffset = Math.floor(absoluteMinutes / 1440);
    const minutesInDay = absoluteMinutes - dayOffset * 1440;
    return at(dayOffset, Math.floor(minutesInDay / 60), minutesInDay % 60);
  };

  /** Mirrors AvailabilityService::dayWindow — null means "fully awake all day". */
  function dayWindowMinutes(dayOffset: number): { wakeMin: number; sleepMin: number } | null {
    // JS's `%` doesn't wrap negative operands the way Python's does
    // (-1 % 7 === -1, not 6) — dayOffset now legitimately goes negative
    // (see RANGE_START_DAY below, for the "yesterday" the color preview
    // needs when today is a Monday), so this has to wrap it into 0..6 by
    // hand rather than relying on a bare `% 7`.
    const dow = exampleWeekDatesMonFirst[((dayOffset % 7) + 7) % 7]!.getUTCDay();
    const config = form.availability[dow];
    if (!config?.wake || !config?.sleep) return null;

    const [wakeHour, wakeMinute] = config.wake.split(':').map(Number);
    const [sleepHour, sleepMinute] = config.sleep.split(':').map(Number);
    const wakeMin = wakeHour! * 60 + wakeMinute!;
    let sleepMin = sleepHour! * 60 + sleepMinute!;
    if (sleepMin <= wakeMin) sleepMin += 1440; // crosses midnight

    return { wakeMin, sleepMin };
  }

  // Real data (AvailabilityService::computeFreeRanges) never has a `free`
  // range overlapping an `unavailable`/`highlighted` one — free is always
  // the complement of busy within the wake/sleep window, computed there,
  // not something the client reconciles. A naive "free spans the whole
  // day" mock breaks that invariant getBlocksForDay/CalendarView's
  // tentative-fade lookup relies on (blocks tiling with no gaps), so the
  // mock has to carve the same gaps a real backend response would.
  //
  // Single source of truth for every made-up event — free/unavailable/
  // highlighted below are ALL derived from this, each clipped to that
  // day's own wake/sleep window. Previously unavailable/highlighted were
  // hand-duplicated as separate fixed clock-time literals instead of being
  // derived from the same busy periods `free` carves around — those two
  // copies could silently drift apart from the window whenever an owner's
  // configured sleep time fell in the middle of one of these fixed hours
  // (e.g. sleep at 11:00 with the Tuesday event still fixed at 9:00–11:30):
  // `free`/`sleep` correctly clipped to the window, but `unavailable` kept
  // extending past it uncapped, so the sleep block's start visibly cut
  // into the tail of the unavailable block instead of the two meeting
  // cleanly — the reported "gap in the second day after the unavailable
  // block".
  const events: { day: number; start: number; end: number; tentativeStart?: boolean; tentativeEnd?: boolean; activity?: string; highlightWords?: string[]; work?: boolean }[] = [
    { day: 0, start: 12 * 60, end: 14 * 60, activity: 'Lunch', highlightWords: ['Alice'] }, // Mon: Lunch with Alice
    { day: 1, start: 9 * 60, end: 11 * 60 + 30 }, // Tue: Team meeting
    { day: 2, start: 14 * 60, end: 16 * 60, tentativeStart: true, tentativeEnd: true }, // Wed: Maybe call
    { day: 3, start: 10 * 60, end: 12 * 60, tentativeStart: true, tentativeEnd: true, activity: 'Coffee', highlightWords: ['Bob'] }, // Thu: Coffee with Bob (fully tentative + highlighted)
    { day: 4, start: 13 * 60, end: 17 * 60, work: true }, // Fri: Workshop (work)
    { day: 5, start: 18 * 60, end: 20 * 60, tentativeEnd: true, activity: 'Dinner', highlightWords: ['Alice'] }, // Sat: Dinner with Alice, open end + highlighted
    { day: 6, start: 15 * 60, end: 17 * 60, tentativeStart: true, activity: 'Call', highlightWords: ['Charlie'] }, // Sun: Call with Charlie, open start + highlighted
  ];

  /** This event's [start, end], clamped to its own day's wake/sleep window — null if the window clips it away entirely (e.g. it falls fully inside a since-configured sleep period). */
  function clippedEventMinutes(event: (typeof events)[number]): [number, number] | null {
    const win = dayWindowMinutes(event.day);
    const windowStart = win ? win.wakeMin : 0;
    const windowEnd = win ? win.sleepMin : 1440;
    const start = Math.max(event.start, windowStart);
    const end = Math.min(event.end, windowEnd);
    return end > start ? [start, end] : null;
  }

  // Events only ever cover day 0..6 (Mon..Sun), but the computed range now
  // reaches one day earlier (day -1, i.e. the Sunday before this Monday —
  // reachable as "yesterday" by colorPreviewExampleDays above whenever
  // today is itself a Monday) and one day later (day 7, next Monday, kept
  // from the range this already had for the sleep wraparound below) than
  // that. Both ends are simply eventless — day -1/day 7 render as fully
  // free/asleep per that day's own window, same as any other day with no
  // events on it.
  const RANGE_START_DAY = -1;
  const RANGE_END_DAY = 8; // exclusive

  const free = Array.from({ length: RANGE_END_DAY - RANGE_START_DAY }, (_, i) => {
    const day = RANGE_START_DAY + i;
    const win = dayWindowMinutes(day);
    const windowStart = win ? win.wakeMin : 0;
    const windowEnd = win ? win.sleepMin : 1440;

    const busyPeriods = events
      .filter((event) => event.day === day)
      .map(clippedEventMinutes)
      .filter((period): period is [number, number] => period !== null);

    const segments: { start: string; end: string }[] = [];
    let cursor = windowStart;
    for (const [busyStart, busyEnd] of busyPeriods) {
      if (busyStart > cursor) segments.push({ start: atAbsMinutes(day * 1440 + cursor), end: atAbsMinutes(day * 1440 + busyStart) });
      cursor = Math.max(cursor, busyEnd);
    }
    if (cursor < windowEnd) segments.push({ start: atAbsMinutes(day * 1440 + cursor), end: atAbsMinutes(day * 1440 + windowEnd) });
    return segments;
  }).flat();

  // Sleep: awake windows per day-offset, merged, then inverted across the
  // whole span — same shape as AvailabilityService::computeSleepBlocks.
  const awakeWindows = Array.from({ length: RANGE_END_DAY - RANGE_START_DAY }, (_, i) => {
    const day = RANGE_START_DAY + i;
    const win = dayWindowMinutes(day);
    return win
      ? { start: day * 1440 + win.wakeMin, end: day * 1440 + win.sleepMin }
      : { start: day * 1440, end: day * 1440 + 1440 };
  }).sort((a, b) => a.start - b.start);

  const mergedAwake: { start: number; end: number }[] = [];
  for (const window of awakeWindows) {
    const last = mergedAwake[mergedAwake.length - 1];
    if (last && window.start <= last.end) {
      last.end = Math.max(last.end, window.end);
    } else {
      mergedAwake.push({ ...window });
    }
  }

  const sleep: { start: string; end: string }[] = [];
  let cursor = RANGE_START_DAY * 1440;
  for (const window of mergedAwake) {
    if (window.start > cursor) sleep.push({ start: atAbsMinutes(cursor), end: atAbsMinutes(window.start) });
    cursor = Math.max(cursor, window.end);
  }
  if (cursor < RANGE_END_DAY * 1440) sleep.push({ start: atAbsMinutes(cursor), end: atAbsMinutes(RANGE_END_DAY * 1440) });

  return {
    free,
    sleep,
    // A highlighted event is still busy time — the real backend always
    // double-lists its range in `unavailable` too, since `highlighted` is
    // an overlay split out of an existing unavailable/free base block
    // (getBlocksForDay's splitByOverlay), not a standalone block of its
    // own. Omitting the base here is exactly what caused the gap/squash.
    unavailable: events
      .map((event) => {
        const clipped = clippedEventMinutes(event);
        if (!clipped) return null;
        return {
          start: atAbsMinutes(event.day * 1440 + clipped[0]),
          end: atAbsMinutes(event.day * 1440 + clipped[1]),
          tentative_start: event.tentativeStart,
          tentative_end: event.tentativeEnd,
        };
      })
      .filter((slot) => slot !== null),
    // Same double-bookkeeping as `highlighted` above — a work event is
    // still busy time too (already double-listed in `unavailable`), this
    // is just the overlay tag on top of it.
    work: events
      .filter((event) => event.work)
      .map((event) => {
        const clipped = clippedEventMinutes(event);
        if (!clipped) return null;
        return {
          start: atAbsMinutes(event.day * 1440 + clipped[0]),
          end: atAbsMinutes(event.day * 1440 + clipped[1]),
          tentative_start: event.tentativeStart,
          tentative_end: event.tentativeEnd,
        };
      })
      .filter((slot) => slot !== null),
    highlighted: events
      .filter((event) => event.activity)
      .map((event) => {
        const clipped = clippedEventMinutes(event);
        if (!clipped) return null;
        return {
          start: atAbsMinutes(event.day * 1440 + clipped[0]),
          end: atAbsMinutes(event.day * 1440 + clipped[1]),
          activity: event.activity,
          highlight_words: event.highlightWords,
          tentative_start: event.tentativeStart,
          tentative_end: event.tentativeEnd,
        };
      })
      .filter((slot) => slot !== null),
  };
});

// Its own form/endpoint (not part of the main settings save below) — see
// SettingsController::updateCalendarUrl's doc comment for why: a pending,
// not-yet-previewed URL should only ever be able to block itself, never
// unrelated settings changes.
//
// Pre-filled with the actual saved URL (not left blank) — this is §0.2
// server-runtime tier, not §0.1 client-vault E2EE, so the server can
// already decrypt it on every recompute regardless; hiding it from the
// owner's own settings page only added confusion, no real confidentiality.
// Saving still requires a fresh Preview click either way (server-enforced),
// so re-showing the existing value doesn't skip that safety check.
const calendarUrlForm = useForm({
  calendar_url: props.calendarUrl ?? '',
  calendar_url_preview_confirmed: false as boolean,
});
const hadSavedCalendarUrl = ref(!!props.calendarUrl);

const form = useForm({
  timezone: props.settings.timezone,
  week_start: props.settings.week_start,
  // None of these seven are pre-filled with their suggested default the
  // way the color-key fields below are — a color slot always needs *some*
  // resolved value to render, but a blank pattern here is a real,
  // functionally distinct state (dnd/nap/work: "genuinely off, matches
  // nothing"; highlight/tentative/open-end/open-start: "use the built-in
  // fallback pattern" — see HighlightMatcher::DEFAULT_CLAUSE_PATTERN /
  // IcsParser's own DEFAULT_*_PATTERN constants). Silently filling the
  // form with the suggestion made an unsaved, still-blank-in-the-database
  // setting look already active — the suggestion is shown as a
  // placeholder instead (every input below already has :placeholder set),
  // same as activity_clause_pattern already did.
  dnd_event_pattern: props.settings.dnd_event_pattern,
  nap_event_pattern: props.settings.nap_event_pattern,
  work_event_pattern: props.settings.work_event_pattern,
  calendar_parsing_mode: props.settings.calendar_parsing_mode,
  highlight_clause_pattern: props.settings.highlight_clause_pattern,
  highlight_split_pattern: props.settings.highlight_split_pattern,
  activity_clause_pattern: props.settings.activity_clause_pattern ?? '',
  tentative_pattern: props.settings.tentative_pattern,
  open_end_pattern: props.settings.open_end_pattern,
  open_start_pattern: props.settings.open_start_pattern,
  public_page_title_en: props.settings.public_page_title_en ?? '',
  public_page_title_hu: props.settings.public_page_title_hu ?? '',
  accent_color_key: props.settings.accent_color_key ?? getDefaultSwatchKey('accent'),
  secondary_color_key: props.settings.secondary_color_key ?? getDefaultSwatchKey('secondary'),
  free_color_key: props.settings.free_color_key ?? getDefaultSwatchKey('free'),
  busy_color_key: props.settings.busy_color_key ?? getDefaultSwatchKey('busy'),
  work_color_key: props.settings.work_color_key ?? getDefaultSwatchKey('work'),
  sleep_color_key: props.settings.sleep_color_key ?? getDefaultSwatchKey('sleep'),
  highlight_color_key: props.settings.highlight_color_key ?? getDefaultSwatchKey('highlighted'),
  free_icon_key: props.settings.free_icon_key ?? getDefaultIconKey('free'),
  busy_icon_key: props.settings.busy_icon_key ?? getDefaultIconKey('busy'),
  work_icon_key: props.settings.work_icon_key ?? getDefaultIconKey('work'),
  sleep_icon_key: props.settings.sleep_icon_key ?? getDefaultIconKey('sleep'),
  highlight_icon_key: props.settings.highlight_icon_key ?? getDefaultIconKey('highlighted'),
  now_color_key: props.settings.now_color_key ?? getDefaultNowColorKey(),
  availability: days.map((_, i) => ({
    wake: props.settings.availability[i]?.wake ?? '',
    sleep: props.settings.availability[i]?.sleep ?? '',
  })),
});

// Live-preview accent/secondary across the whole dashboard chrome (nav,
// links, muted text) as these two pickers are dragged, not just in this
// page's own preview panels below — see liveThemePreview.ts. Cleared on
// unmount so navigating away restores the owner's actually-saved colors.
// Resolved against whichever theme the dashboard is actually rendered in
// right now, same as DashboardLayout does for the saved (non-live) colors
// — dragging the dark-mode picker while viewing in light mode shouldn't
// visibly change anything until the theme is actually switched.
const resolvedTheme = useResolvedTheme();
const liveTheme = useLiveThemePreview();
watch(
  () => [form.accent_color_key, form.secondary_color_key, resolvedTheme.value] as const,
  ([accentKey, secondaryKey, theme]) => {
    liveTheme.value = {
      accent: resolveSwatchHex(accentKey, 'accent', theme),
      secondary: resolveSwatchHex(secondaryKey, 'secondary', theme),
    };
  },
  { immediate: true },
);
onUnmounted(() => {
  liveTheme.value = null;
});

const previewStatus = ref('');
const previewing = ref(false);
const calendarUrlRevealed = ref(false);
const previewResult = ref<{ detected_mode: string; slotCount: number } | null>(null);
/** Set once a real preview fetch succeeds — the calendar preview panel below switches from the synthetic example to this actual data. */
const previewAvailability = ref<AvailabilityResponse | null>(null);
const previewDays = computed(() => {
  const weekStart = startOfWeekFns(new Date(), { weekStartsOn: form.week_start as 0 | 1 | 2 | 3 | 4 | 5 | 6 });
  return Array.from({ length: 7 }, (_, i) => addDaysFns(weekStart, i));
});
/**
 * The light/dark dual color-slot preview only shows 3 days — plenty to
 * judge how the colors read, and two side-by-side 7-day calendars would be
 * cramped at half page width each. The synthetic-example branch centers
 * on today (colorPreviewExampleDays) so the current-time indicator is
 * always visible regardless of what day of the week it is; the real-
 * fetched-preview branch still takes the first 3 days of the week instead
 * — CalendarPreviewController's own computed range starts at today and
 * only goes forward, so there's no "yesterday" data to center on there
 * even if this centered the same way.
 */
const colorPreviewVisibleDays = computed(() => (previewAvailability.value ? previewDays.value.slice(0, 3) : colorPreviewExampleDays.value));
/** Wake & sleep times table row order — same weekday indices (0=Sun..6=Sat) as form.availability, just walked starting from week_start instead of always Sunday. */
const orderedDayIndices = computed(() => Array.from({ length: 7 }, (_, i) => (form.week_start + i) % 7));
const currentTimePct = (() => {
  const now = new Date();
  return ((now.getHours() * 60 + now.getMinutes()) / 1440) * 100;
})();

/**
 * <input type="color"> only ever gives a solid hex, no alpha — binding it
 * straight to --wtf-color-* would make these preview blocks fully opaque,
 * losing the transparent-wash treatment every block gets on the real /free
 * page (see color-utils.ts). Re-applies the same alpha so what's previewed
 * here actually matches what a viewer would see.
 */
function previewStyleFor(theme: 'light' | 'dark') {
  const accent = resolveSwatchHex(form.accent_color_key, 'accent', theme);
  const free = resolveSwatchHex(form.free_color_key, 'free', theme);
  const busy = resolveSwatchHex(form.busy_color_key, 'busy', theme);
  const work = resolveSwatchHex(form.work_color_key, 'work', theme);
  const sleep = resolveSwatchHex(form.sleep_color_key, 'sleep', theme);
  const highlighted = resolveSwatchHex(form.highlight_color_key, 'highlighted', theme);
  const alpha = BLOCK_ALPHA[theme];

  return {
    '--wtf-accent': accent,
    '--wtf-accent-rgb': hexToRgbTriplet(accent),
    '--wtf-accent-text': yiqTextColor(accent),
    '--wtf-color-free': hexToRgba(free, alpha.free),
    '--wtf-hue-free': free,
    '--wtf-color-busy': hexToRgba(busy, alpha.busy),
    '--wtf-color-work': hexToRgba(work, alpha.work),
    '--wtf-hue-work': work,
    '--wtf-color-sleep': hexToRgba(sleep, alpha.sleep),
    '--wtf-hue-sleep': sleep,
    '--wtf-color-highlighted': hexToRgba(highlighted, alpha.highlighted),
    '--wtf-hue-highlighted': highlighted,
    '--wtf-color-now': resolveNowColorHex(form.now_color_key, theme),
  };
}

// Both themes are always previewed side by side (see wtf-theme-preview in
// dark-theme.css — a self-contained, scoped copy of the :root[data-bs-
// theme] variable blocks) rather than only the one the settings page
// itself is currently rendered in, so an owner can see how a color choice
// reads on the theme they're NOT currently looking at without switching.
const previewStyleLight = computed(() => previewStyleFor('light'));
const previewStyleDark = computed(() => previewStyleFor('dark'));
/** For the calendar-URL preview panel further up the page, which isn't wrapped in its own .wtf-theme-preview scope — it just follows the page's own live theme like everything else on it. */
const previewStyleLive = computed(() => previewStyleFor(resolvedTheme.value));
const previewSecondaryColorLight = computed(() => resolveSwatchHex(form.secondary_color_key, 'secondary', 'light'));
const previewSecondaryColorDark = computed(() => resolveSwatchHex(form.secondary_color_key, 'secondary', 'dark'));

/** Fed to both preview CalendarView instances below — icons aren't theme-reactive (see icon-palette.ts), so this is a single computed, not a light/dark pair. */
const formIcons = computed(() => ({
  free: resolveIcon(form.free_icon_key, 'free'),
  busy: resolveIcon(form.busy_icon_key, 'busy'),
  work: resolveIcon(form.work_icon_key, 'work'),
  sleep: resolveIcon(form.sleep_icon_key, 'sleep'),
  highlighted: resolveIcon(form.highlight_icon_key, 'highlighted'),
}));

function onUrlInput(): void {
  calendarUrlForm.calendar_url_preview_confirmed = false;
  previewResult.value = null;
  previewAvailability.value = null;
  calendarUrlJustSaved.value = false;
}

async function preview(): Promise<void> {
  if (!calendarUrlForm.calendar_url) return;

  previewStatus.value = 'Fetching…';
  previewing.value = true;

  try {
    const availabilitySettings = Object.fromEntries(form.availability.map((day, i) => [i, day]));

    const { data } = await axios.post('/settings/calendar/preview', {
      calendar_url: calendarUrlForm.calendar_url,
      timezone: form.timezone,
      calendar_parsing_mode: form.calendar_parsing_mode,
      dnd_event_pattern: form.dnd_event_pattern,
      nap_event_pattern: form.nap_event_pattern,
      work_event_pattern: form.work_event_pattern,
      highlight_clause_pattern: form.highlight_clause_pattern,
      highlight_split_pattern: form.highlight_split_pattern,
      activity_clause_pattern: form.activity_clause_pattern,
      tentative_pattern: form.tentative_pattern,
      open_end_pattern: form.open_end_pattern,
      open_start_pattern: form.open_start_pattern,
      availability_settings: availabilitySettings,
    });

    previewResult.value = {
      detected_mode: data.detected_mode,
      slotCount: data.free.length + data.highlighted.length + data.unavailable.length + data.sleep.length,
    };
    // Suggest a parsing mode from what the feed actually contains, but only
    // for a brand-new setup — re-previewing an already-saved URL must never
    // silently clobber a mode the owner deliberately chose. "mixed" maps to
    // full_detail, not free_busy_only: that's the only choice that doesn't
    // drop title matching for the feed's real-titled events.
    if (!hadSavedCalendarUrl.value) {
      form.calendar_parsing_mode = data.detected_mode === 'free_busy_only' ? 'free_busy_only' : 'full_detail';
    }
    previewAvailability.value = {
      free: data.free,
      highlighted: data.highlighted,
      unavailable: data.unavailable,
      work: data.work,
      sleep: data.sleep,
    };
    calendarUrlForm.calendar_url_preview_confirmed = true;
    previewStatus.value = 'Looks good.';
  } catch (error) {
    console.error(error);
    previewStatus.value = 'Could not fetch that calendar. Check the URL and try again.';
    calendarUrlForm.calendar_url_preview_confirmed = false;
  } finally {
    previewing.value = false;
  }
}

const calendarUrlJustSaved = ref(false);

function saveCalendarUrl(): void {
  calendarUrlJustSaved.value = false;

  calendarUrlForm.patch('/settings/calendar-url', {
    preserveScroll: true,
    onSuccess: () => {
      // Shown back verbatim (see the form's own doc comment above), so a
      // successful save keeps the just-saved value visible rather than
      // clearing the field — update the form's own "reset to" baseline to
      // match, and require a fresh Preview for anything typed after this.
      calendarUrlForm.calendar_url_preview_confirmed = false;
      calendarUrlForm.defaults();
      hadSavedCalendarUrl.value = true;
      previewResult.value = null;
      previewStatus.value = '';
      // The page-level flash alert lands at the very top of a long page and
      // preserveScroll keeps the view right here — without this, saving
      // leaves no visible trace where the owner is actually looking.
      calendarUrlJustSaved.value = true;
    },
  });
}

/** Also used by the "Use" button next to each pattern field's suggested/default value (below) — putting that value straight into the field is the same operation as resetting a color to a literal. */
function setFormField(field: keyof Settings, value: string): void {
  (form as unknown as Record<string, string>)[field] = value;
}

/** Genuinely clears every day to blank — distinct from the "Reset" button next to it, which restores the last-saved/loaded values instead of wiping them. */
function clearAvailability(): void {
  form.availability = days.map(() => ({ wake: '', sleep: '' }));
}

/**
 * Field lists for each section's own "Reset" button — form.reset(...) only
 * reverts the fields named, not the other two sections' worth that happen
 * to share this same useForm() instance, since a "Reset" clicked in one
 * card resetting an owner's still-unsaved edits in a completely different
 * card would be a nasty surprise. The set reverted here is exactly the
 * fields collected by submit()'s own payload for that card — see the
 * template below for which BFormGroups belong to which card.
 */
const EVENT_MATCHING_FIELDS = [
  'dnd_event_pattern', 'nap_event_pattern', 'work_event_pattern',
  'highlight_clause_pattern', 'highlight_split_pattern', 'activity_clause_pattern',
  'tentative_pattern', 'open_end_pattern', 'open_start_pattern',
] as const;
const PUBLIC_PAGE_FIELDS = [
  'public_page_title_en', 'public_page_title_hu',
  'accent_color_key', 'secondary_color_key', 'free_color_key', 'busy_color_key', 'work_color_key', 'sleep_color_key', 'highlight_color_key',
  'free_icon_key', 'busy_icon_key', 'work_icon_key', 'sleep_icon_key', 'highlight_icon_key',
  'now_color_key',
] as const;

function submit(): void {
  form.transform((data) => ({
    ...data,
    availability: Object.fromEntries(data.availability.map((day, i) => [i, day])),
  })).patch('/settings', {
    preserveScroll: true,
    // Updates form's own "reset to" baseline to the values just saved —
    // without this, every section's Reset button would always revert to
    // whatever was on the page at the very first load, never to a save
    // made sometime after that.
    onSuccess: () => form.defaults(),
  });
}

/** Mirrors onUrlInput's own cleanup — form.reset() sets calendar_url programmatically, which doesn't fire the native @input event onUrlInput is normally bound to. */
function resetCalendarUrl(): void {
  calendarUrlForm.reset();
  onUrlInput();
}
</script>

<template>
  <Head title="Settings" />

  <BAlert :model-value="!!page.props.flash?.status" variant="success">{{ page.props.flash?.status }}</BAlert>

  <BCard class="mb-4">
    <h1 class="h3 mb-4">Settings</h1>

    <h2 class="h5 mb-3">Calendar</h2>

    <!--
      Its own form/endpoint, deliberately separate from the big settings
      form below — see SettingsController::updateCalendarUrl's doc
      comment. A pending, not-yet-previewed URL must only ever be able to
      block itself, never an unrelated change like timezone.
    -->
    <form @submit.prevent="saveCalendarUrl">
      <BFormGroup label-for="calendar_url" class="mb-3">
        <template #label>
          Calendar URL (ICS feed)
          <BBadge v-if="hadSavedCalendarUrl" variant="success" class="ms-1">Configured</BBadge>
          <BBadge v-else variant="secondary" class="ms-1">Not set</BBadge>
        </template>
        <BInputGroup>
          <BFormInput
            id="calendar_url"
            v-model="calendarUrlForm.calendar_url"
            :type="calendarUrlRevealed ? 'text' : 'password'"
            placeholder="https://..."
            @input="onUrlInput"
          />
          <BButton
            variant="outline-secondary"
            :aria-label="calendarUrlRevealed ? 'Hide calendar URL' : 'Show calendar URL'"
            @click="calendarUrlRevealed = !calendarUrlRevealed"
          >
            <FontAwesomeIcon :icon="calendarUrlRevealed ? faEyeSlash : faEye" />
          </BButton>
        </BInputGroup>
        <template #description>
          {{ hadSavedCalendarUrl
            ? "Edit it and preview before saving to replace it."
            : "Paste your calendar's ICS URL, then preview it before saving." }}
        </template>
        <div v-if="calendarUrlForm.errors.calendar_url" class="text-danger small">{{ calendarUrlForm.errors.calendar_url }}</div>
      </BFormGroup>

      <BButton
        variant="outline-secondary"
        :disabled="previewing || !calendarUrlForm.calendar_url"
        @click="preview"
      >
        Preview
      </BButton>
      <BButton
        v-if="calendarUrlForm.calendar_url_preview_confirmed"
        type="submit"
        variant="primary"
        class="ms-2"
        :disabled="calendarUrlForm.processing"
      >
        Save calendar URL
      </BButton>
      <BButton variant="outline-secondary" class="ms-2" @click="resetCalendarUrl">Reset</BButton>
      <span v-if="calendarUrlJustSaved" class="small text-success ms-2">Saved</span>
      <span v-else class="small text-muted ms-2">{{ previewStatus }}</span>

      <div v-if="previewResult" class="mt-3">
        <p class="mb-1"><strong>Detected feed type:</strong> {{ previewResult.detected_mode }}</p>
        <p class="mb-0 text-muted small">{{ previewResult.slotCount }} block(s) computed for the next 14 days.</p>
      </div>

      <div
        v-if="previewAvailability && !calendarUrlJustSaved"
        class="mt-3"
        :style="previewStyleLive"
      >
        <CalendarView
          :visible-days="previewDays"
          :free-slots="previewAvailability.free"
          :highlighted-slots="previewAvailability.highlighted"
          :unavailable-slots="previewAvailability.unavailable"
          :work-slots="previewAvailability.work"
          :sleep-slots="previewAvailability.sleep"
          :icons="formIcons"
          :pending="false"
          :has-error="false"
          :has-any-free-time="true"
          :timezone="form.timezone"
          :show-blocks="true"
          :show-current-time="true"
          :current-time-pct="currentTimePct"
        />
      </div>
    </form>

    <hr class="my-4">

    <!--
      Parsing mode/timezone/week start all directly affect how the URL
      above gets parsed and previewed, so they live in this card too now,
      not a separate "Parsing & timezone" section — still part of the main
      settings `form`/submit() below, not the calendar_url mini-form above;
      physical placement here doesn't change which endpoint saves them.
    -->
    <BFormGroup label="Parsing mode" label-for="calendar_parsing_mode" class="mb-3">
      <BFormSelect id="calendar_parsing_mode" v-model="form.calendar_parsing_mode">
        <option value="full_detail">Full detail (event titles are used for highlighting)</option>
        <option value="free_busy_only">Free/busy only (track availability only, ignoring event titles)</option>
      </BFormSelect>
      <template #description>
        Previewing your calendar URL above picks one of these for you the first time you set it up, based on what
        your feed actually contains — change it here any time afterward if it guessed wrong.
      </template>
    </BFormGroup>

    <BFormGroup label="Timezone" label-for="timezone" class="mb-3">
      <BFormSelect id="timezone" v-model="form.timezone">
        <option v-for="tz in timezones" :key="tz" :value="tz">{{ tz }}</option>
      </BFormSelect>
    </BFormGroup>

    <BFormGroup label="Week starts on" label-for="week_start" class="mb-3">
      <BFormSelect id="week_start" v-model="form.week_start">
        <option v-for="opt in weekStartOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
      </BFormSelect>
      <template #description>
        Applies to your public calendar's week/month view, both preview calendars below, and
        the row order of the Wake &amp; sleep times table.
      </template>
    </BFormGroup>
  </BCard>

  <form @submit.prevent="submit">
    <BCard class="mb-4">
        <h2 class="h5 mb-3">Event title matching rules</h2>

        <BAlert :model-value="true" variant="secondary" class="small">
          <p class="mb-2">
            <strong>What these text-match fields actually do:</strong> what you type isn't
            compared for an exact match — it's used as the body of a
            <a href="https://developer.mozilla.org/en-US/docs/Web/JavaScript/Guide/Regular_expressions" target="_blank" rel="noopener">regular expression</a>,
            tested case-insensitively against <em>anywhere</em> in the event's title by default.
            A quick crash course in the handful of regex bits that actually come up on this page:
          </p>
          <p class="mb-1">Each field below is badged with how it actually uses your pattern:</p>
          <dl class="wtf-badge-legend mb-2">
            <dt><BBadge variant="secondary" class="align-middle">Boolean</BBadge></dt>
            <dd>Just tests whether it matches at all — nothing is captured.</dd>
            <dt><BBadge variant="info" text="dark" class="align-middle">Capture</BBadge></dt>
            <dd>
              Requires exactly one real <code>(…)</code> capture group, whose contents are the
              actual thing used (see the <code>(…)</code>/<code>(?:…)</code> bullets below — this
              is checked and rejected on save if it's missing or there's more than one).
            </dd>
            <dt><BBadge variant="warning" text="dark" class="align-middle">Flag</BBadge></dt>
            <dd>
              Also a boolean match (no group read), but with two side effects together: it marks
              the event tentative at that edge (start/end/both, depending on the field)
              <em>and</em> removes the matched marker text from the title used for pattern
              matching.
            </dd>
            <dt><BBadge variant="primary" class="align-middle">Split</BBadge></dt>
            <dd>
              Isn't matched against a title at all — it's a delimiter used to break one field's
              captured text into individual pieces.
            </dd>
          </dl>
          <ul class="mb-2">
            <li>
              Unanchored by default — a plain word like <code>dnd</code> matches a title that
              merely <em>contains</em> "dnd" anywhere, case-insensitively. "Team DND block"
              matches just as much as a title that's only "DND".
            </li>
            <li>
              <code>^</code> anchors to the very <em>start</em> of the title — <code>^dnd</code>
              only matches a title that begins with "dnd".
            </li>
            <li>
              <code>$</code> anchors to the very <em>end</em> of the title — <code>dnd$</code>
              only matches a title that ends with "dnd".
            </li>
            <li>
              <code>^</code> and <code>$</code> together require the pattern to match the
              <em>whole</em> title, not just part of it — <code>^dnd$</code> matches a title
              that's exactly "dnd" (still case-insensitive), but not "Team DND block".
            </li>
            <li>
              <code>(…)</code> groups characters together — mainly to build an alternation like
              <code>(dnd|do not disturb)</code>, or so <code>?</code>/<code>*</code>/<code>+</code>
              apply to more than one character at once. Parenthesized text is also "captured",
              but only the Highlight and Activity fields below actually use a capture group's
              contents — everywhere else on this page, <code>(…)</code> is just for grouping.
            </li>
            <li>
              <code>(?:…)</code> is the same grouping, just <em>non-capturing</em> — it still
              lets you write an alternation like <code>(?:dnd|do not disturb)</code> without
              that group counting as the pattern's capture group. The Highlight and Activity
              fields below require exactly one real <code>(…)</code> capture group each (the
              one whose contents actually get used) — reach for <code>(?:…)</code> for any
              other grouping in those two fields so it doesn't count against that limit.
            </li>
            <li>
              <code>[…]</code> is a character class — it matches any <em>one</em> of the
              characters listed inside, not the sequence as a whole. <code>[,&amp;/]</code> (the
              Highlight name-split expression's own default, below) matches a single comma,
              ampersand, <em>or</em> slash — not that whole three-character string.
              <code>-</code> inside a class builds a <em>range</em> (<code>[a-z]</code> is every
              lowercase letter) rather than meaning a literal hyphen — if you actually want a
              literal <code>-</code> in a class and aren't sure whether it'll be read as a range,
              put it right at the start or the end of the class (e.g. <code>[,&amp;/-]</code>),
              where it can't form one.
            </li>
            <li>
              <code>?</code>, <code>*</code>, and <code>+</code> repeat whatever came right
              before them — a single character, or a whole <code>(…)</code>/<code>(?:…)</code>
              group — but as a fixed shorthand rather than a chosen count: <code>?</code> means
              "zero or one" (optional), <code>*</code> means "zero or more", and <code>+</code>
              means "one or more". <code>colou?r</code> matches both "color" and "colour";
              <code>lo+l</code> matches "lol", "lool", "loool", and so on, but not "ll".
            </li>
            <li>
              <code>{n}</code>, <code>{n,}</code>, and <code>{n,m}</code> also repeat whatever came
              right before them the same way, but for an exact or bounded count you choose instead
              of one of those three fixed shorthands: exactly <code>n</code> times, <code>n</code>
              or more times, or between <code>n</code> and <code>m</code> times. <code>a{2,4}</code>
              matches "aa", "aaa", or "aaaa", but not a single "a" or five of them.
            </li>
            <li>
              <code>.</code> matches <em>any single character</em> (except a newline) — not a
              literal period the way it looks. <code>a.c</code> matches "abc", "a c", "a-c", and so
              on, just as much as "a.c". If you actually want a literal period, escape it:
              <code>a\.c</code> matches only "a.c".
            </li>
            <li>
              <code>\</code> in front of any of the metacharacters above (<code>\.</code>,
              <code>\?</code>, <code>\[</code>, <code>\]</code>, <code>\{</code>, <code>\}</code>,
              <code>\(</code>, <code>\)</code>, <code>\\</code>, etc.) strips that character's
              special meaning so it matches only itself — the pattern editors above color these
              as plain text, since that's really all they are. The same <code>\</code> in front of
              a letter usually means the opposite — it <em>adds</em> special meaning instead
              (<code>\d</code> any digit, <code>\w</code> any letter/digit/underscore,
              <code>\s</code> any whitespace) — those get their own color above since they're a
              genuinely different kind of match, not just an escaped literal.
            </li>
          </ul>
          <p class="mb-2">
            If what you type isn't valid regex syntax, matching just silently never happens
            (fails closed) rather than breaking your page. Leave a field blank to turn that
            feature off entirely.
          </p>
          <p class="mb-0">
            The live previews below run in your browser's own regex engine, just to give you a
            quick sanity check as you type — the actual matching that decides what a viewer sees
            always happens server-side, in PHP's regex engine. The two are compatible for
            everything covered above, but if you reach for more exotic regex syntax, a rare
            mismatch between the two engines is possible; the server-side result is always the
            one that counts.
          </p>
        </BAlert>

        <!--
          Each field gets its own input+preview row (rather than one column
          of all 8 inputs stacked above a second column of all 8 previews)
          so on mobile — where col-md-6 collapses to full width and the two
          columns stack — a field's own preview appears immediately after
          it, not after scrolling past every other field first.
        -->
        <div class="row mb-3">
          <div class="col-md-6">
            <BFormGroup label-for="dnd_event_pattern" class="mb-0">
              <template #label>DND event regular expression <BBadge variant="secondary" class="align-middle">Boolean</BBadge></template>
              <RegexPatternInput id="dnd_event_pattern" v-model="form.dnd_event_pattern" />
              <template #description>
                A match hides the event entirely from viewers (unless a share link bypasses it).
                Suggested: <RegexHighlightedCode :pattern="defaults.dndEventPattern" />
                <BButton variant="link" size="sm" class="p-0 align-baseline" @click="setFormField('dnd_event_pattern', defaults.dndEventPattern)">Use</BButton>
              </template>
            </BFormGroup>
          </div>
          <div class="col-md-6">
            <div class="wtf-pattern-preview-panel">
              <p class="small text-muted mb-1">Live preview — <code>{{ form.dnd_event_pattern || '(blank, off)' }}</code></p>
              <PatternPreview
                :pattern="form.dnd_event_pattern ?? ''"
                :examples="['DND', 'Team DND block', 'dnd - focus time', 'Focus time', 'Lunch with Sarah']"
                mode="match"
              />
            </div>
          </div>
        </div>

        <div class="row mb-3">
          <div class="col-md-6">
            <BFormGroup label-for="nap_event_pattern" class="mb-0">
              <template #label>Nap event regular expression <BBadge variant="secondary" class="align-middle">Boolean</BBadge></template>
              <RegexPatternInput id="nap_event_pattern" v-model="form.nap_event_pattern" />
              <template #description>
                A match shows the event as sleep instead of busy.
                Suggested: <RegexHighlightedCode :pattern="defaults.napEventPattern" />
                <BButton variant="link" size="sm" class="p-0 align-baseline" @click="setFormField('nap_event_pattern', defaults.napEventPattern)">Use</BButton>
              </template>
            </BFormGroup>
          </div>
          <div class="col-md-6">
            <div class="wtf-pattern-preview-panel">
              <p class="small text-muted mb-1">Live preview — <code>{{ form.nap_event_pattern || '(blank, off)' }}</code></p>
              <PatternPreview
                :pattern="form.nap_event_pattern ?? ''"
                :examples="['Nap', 'Afternoon nap', 'NAP TIME', 'Sleep', 'Standup meeting']"
                mode="match"
              />
            </div>
          </div>
        </div>

        <div class="row mb-3">
          <div class="col-md-6">
            <BFormGroup label-for="work_event_pattern" class="mb-0">
              <template #label>Work event regular expression <BBadge variant="secondary" class="align-middle">Boolean</BBadge></template>
              <RegexPatternInput id="work_event_pattern" v-model="form.work_event_pattern" />
              <template #description>
                A match counts toward the "work" slice of the dashboard's time-breakdown widget and
                the /free calendar's own work category.
                Suggested: <RegexHighlightedCode :pattern="defaults.workEventPattern" />
                <BButton variant="link" size="sm" class="p-0 align-baseline" @click="setFormField('work_event_pattern', defaults.workEventPattern)">Use</BButton>
              </template>
            </BFormGroup>
          </div>
          <div class="col-md-6">
            <div class="wtf-pattern-preview-panel">
              <p class="small text-muted mb-1">Live preview — <code>{{ form.work_event_pattern || '(blank, off)' }}</code></p>
              <PatternPreview
                :pattern="form.work_event_pattern ?? ''"
                :examples="['Work', 'Work block', 'WFH', 'Team standup', 'Lunch with Sarah']"
                mode="match"
              />
            </div>
          </div>
        </div>

        <div class="row mb-3">
          <div class="col-md-6">
            <BFormGroup label-for="highlight_clause_pattern" class="mb-0">
              <template #label>Highlight regular expression <BBadge variant="info" text="dark" class="align-middle">Capture</BBadge></template>
              <RegexPatternInput id="highlight_clause_pattern" v-model="form.highlight_clause_pattern" :placeholder="defaults.highlightClausePattern" />
              <template #description>
                Same regex-body rules as above, but everything after "with"/"w/" is captured as a
                whole (to the end of the title), then split — using the name-split expression
                below — into individual names, each checked as a <em>substring</em> (not a
                whole-word match, and this comparison is case-<strong>sensitive</strong>) against
                a share link's own configured highlight words (set per-link, not here). "Dinner
                with Alice, Bob" checks both "Alice" and "Bob" individually. "Host X" also
                matches, marking the event as the calendar owner hosting X; "Visit X" marks the
                owner visiting X. Leave blank to fall back to the built-in default rather than
                turning matching off.
                Default: <RegexHighlightedCode :pattern="defaults.highlightClausePattern" />
                <BButton variant="link" size="sm" class="p-0 align-baseline" @click="setFormField('highlight_clause_pattern', defaults.highlightClausePattern)">Use</BButton>
              </template>
            </BFormGroup>
          </div>
          <div class="col-md-6">
            <div class="wtf-pattern-preview-panel">
              <p class="small text-muted mb-1">
                Live preview — <code>{{ form.highlight_clause_pattern || defaults.highlightClausePattern }}</code>
                <br><span class="text-muted">(against sample configured words "Alice", "Bob")</span>
              </p>
              <PatternPreview
                :pattern="form.highlight_clause_pattern || defaults.highlightClausePattern"
                :examples="['Dinner with Alice', 'Call w/ Bob', 'Team sync', 'Dinner with Charlie, Alice, Bob', 'Host Alice', 'Visit Bob']"
                :sample-words="['Alice', 'Bob']"
                :split-pattern="form.highlight_split_pattern || defaults.highlightSplitPattern"
                mode="tokens"
              />
            </div>
          </div>
        </div>

        <div class="row mb-3">
          <div class="col-md-6">
            <BFormGroup label-for="highlight_split_pattern" class="mb-0">
              <template #label>Highlight name-split expression <BBadge variant="primary" class="align-middle">Split</BBadge></template>
              <RegexPatternInput id="highlight_split_pattern" v-model="form.highlight_split_pattern" :placeholder="defaults.highlightSplitPattern" />
              <template #description>
                A clause can name more than one person — this splits the Highlight field's own
                capture (e.g. "Alice, Bob" from "Dinner with Alice, Bob") into individual names
                before each is checked. Each resulting piece is always trimmed of surrounding
                whitespace, so the default (comma, ampersand, or slash — "Alice, Bob", "Alice &amp;
                Bob", and "Alice/Bob" all split the same way) doesn't care about spacing around
                whichever one shows up — override this only if you use a different separator
                entirely (e.g. <code>;\s*</code>). Leave blank to fall back to the built-in
                default rather than turning splitting off (a clause is always split on
                <em>something</em>).
                Default: <RegexHighlightedCode :pattern="defaults.highlightSplitPattern" />
                <BButton variant="link" size="sm" class="p-0 align-baseline" @click="setFormField('highlight_split_pattern', defaults.highlightSplitPattern)">Use</BButton>
              </template>
            </BFormGroup>
          </div>
          <div class="col-md-6">
            <div class="wtf-pattern-preview-panel">
              <p class="small text-muted mb-1">
                Live preview — splitting on
                <code>{{ form.highlight_split_pattern || defaults.highlightSplitPattern }}</code>
                <br><span class="text-muted">(bolded pieces match one of the sample configured words "Alicia", "Bob", "Damien", "George", "ia, Bob")</span>
              </p>
              <PatternPreview
                pattern="(.+)"
                :examples="['Alicia, Bob', 'Cleo/Damien/Ed', 'Frank & George']"
                :sample-words="['Alicia', 'Bob', 'Damien', 'George', 'ia, Bob']"
                :split-pattern="form.highlight_split_pattern || defaults.highlightSplitPattern"
                mode="split"
              />
            </div>
          </div>
        </div>

        <div class="row mb-3">
          <div class="col-md-6">
            <BFormGroup label-for="activity_clause_pattern" class="mb-0">
              <template #label>Activity regular expression <BBadge variant="info" text="dark" class="align-middle">Capture</BBadge></template>
              <BAlert variant="warning" :model-value="true" class="small mb-2">
                <strong>If you set this, the activity itself — not just who an event is with —
                will be shown, but only to a viewer whose share link is already highlighting that
                event</strong> (i.e. someone actually mentioned in it, per the highlight clause
                above — not anyone else with a link to your calendar). E.g. "Dinner" from "Dinner
                with Alice" is shown only to Alice's own link. Leave it blank (the default) and
                nothing is ever extracted or shown, no matter how a matched event's title reads.
              </BAlert>
              <RegexPatternInput id="activity_clause_pattern" v-model="form.activity_clause_pattern" />
              <template #description>
                A separate pattern from the highlight clause above — its capture group is the
                freetext <em>before</em> "with"/"w/" (e.g. "Dinner" in "Dinner with Alice"). Only
                ever applied to an event that already matched a highlight word, and only shown if
                the individual share link viewing it also has its own "show activity" option on
                (a link-level toggle, not here). Same regex-body rules as the fields above.
                Suggested (matches the highlight clause above):
                <RegexHighlightedCode :pattern="defaults.activityClausePattern" />
                <BButton variant="link" size="sm" class="p-0 align-baseline" @click="setFormField('activity_clause_pattern', defaults.activityClausePattern)">Use</BButton>
              </template>
            </BFormGroup>
          </div>
          <div class="col-md-6">
            <div class="wtf-pattern-preview-panel">
              <p class="small text-muted mb-1">
                Live preview — <code>{{ form.activity_clause_pattern || '(blank, off)' }}</code>
              </p>
              <PatternPreview
                :pattern="form.activity_clause_pattern"
                :examples="['Dinner with Alice', 'Call w/ Bob', 'Team sync', 'Coffee then gym with Charlie, Daniel']"
                mode="extract"
              />
            </div>
          </div>
        </div>

        <div class="row mb-3">
          <div class="col-md-6">
            <BFormGroup label-for="tentative_pattern" class="mb-0">
              <template #label>Tentative regular expression <BBadge variant="warning" text="dark" class="align-middle">Flag</BBadge></template>
              <RegexPatternInput id="tentative_pattern" v-model="form.tentative_pattern" :placeholder="defaults.tentativePattern" />
              <template #description>
                Same regex-body rules as above. An event whose title matches this (in addition to
                any calendar-provided "tentative" status) is shown to viewers as tentative — both
                its start and end are shown as unknown — and the matched text is stripped from the
                title used for pattern matching. The default matches a trailing <code>(?)</code>,
                e.g. "Maybe lunch (?)" &rarr; "Maybe lunch". Leave blank to fall back to that
                default rather than turning detection off.
                Default: <RegexHighlightedCode :pattern="defaults.tentativePattern" />
                <BButton variant="link" size="sm" class="p-0 align-baseline" @click="setFormField('tentative_pattern', defaults.tentativePattern)">Use</BButton>
              </template>
            </BFormGroup>
          </div>
          <div class="col-md-6">
            <div class="wtf-pattern-preview-panel">
              <p class="small text-muted mb-1">
                Live preview — <code>{{ form.tentative_pattern || defaults.tentativePattern }}</code>
              </p>
              <PatternPreview
                :pattern="form.tentative_pattern || defaults.tentativePattern"
                :examples="['Maybe lunch (?)', 'Team standup', 'Coffee with Alice (?)', 'Workshop']"
                mode="match"
              />
            </div>
          </div>
        </div>

        <div class="row mb-3">
          <div class="col-md-6">
            <BFormGroup label-for="open_end_pattern" class="mb-0">
              <template #label>Open-end regular expression <BBadge variant="warning" text="dark" class="align-middle">Flag</BBadge></template>
              <RegexPatternInput id="open_end_pattern" v-model="form.open_end_pattern" :placeholder="defaults.openEndPattern" />
              <template #description>
                For an event that's definitely happening but has no known end time (e.g. it runs
                until whenever it's over). Same regex-body rules as above; matched text is stripped
                the same way. The default matches a trailing <code>(-?)</code>, e.g. "Dinner (-?)"
                &rarr; "Dinner", shown to viewers with a known start and an open end. Leave blank to
                fall back to that default rather than turning detection off.
                Default: <RegexHighlightedCode :pattern="defaults.openEndPattern" />
                <BButton variant="link" size="sm" class="p-0 align-baseline" @click="setFormField('open_end_pattern', defaults.openEndPattern)">Use</BButton>
              </template>
            </BFormGroup>
          </div>
          <div class="col-md-6">
            <div class="wtf-pattern-preview-panel">
              <p class="small text-muted mb-1">
                Live preview — <code>{{ form.open_end_pattern || defaults.openEndPattern }}</code>
              </p>
              <PatternPreview
                :pattern="form.open_end_pattern || defaults.openEndPattern"
                :examples="['Dinner (-?)', 'Team standup', 'Party (-?)', 'Workshop']"
                mode="match"
              />
            </div>
          </div>
        </div>

        <div class="row mb-3">
          <div class="col-md-6">
            <BFormGroup label-for="open_start_pattern" class="mb-0">
              <template #label>Open-start regular expression <BBadge variant="warning" text="dark" class="align-middle">Flag</BBadge></template>
              <RegexPatternInput id="open_start_pattern" v-model="form.open_start_pattern" :placeholder="defaults.openStartPattern" />
              <template #description>
                Same idea as open-end above, for an event whose start time isn't known but which
                definitely ends by a known time. The default matches a trailing <code>(?-)</code>,
                e.g. "Dinner (?-)" &rarr; "Dinner", shown to viewers with an open start and a known
                end. Leave blank to fall back to that default rather than turning detection off.
                Default: <RegexHighlightedCode :pattern="defaults.openStartPattern" />
                <BButton variant="link" size="sm" class="p-0 align-baseline" @click="setFormField('open_start_pattern', defaults.openStartPattern)">Use</BButton>
              </template>
            </BFormGroup>
          </div>
          <div class="col-md-6">
            <div class="wtf-pattern-preview-panel">
              <p class="small text-muted mb-1">
                Live preview — <code>{{ form.open_start_pattern || defaults.openStartPattern }}</code>
              </p>
              <PatternPreview
                :pattern="form.open_start_pattern || defaults.openStartPattern"
                :examples="['Dinner (?-)', 'Team standup', 'Party (?-)', 'Workshop']"
                mode="match"
              />
            </div>
          </div>
        </div>

      <template #footer>
        <BButton type="submit" variant="primary" :disabled="form.processing">Save settings</BButton>
        <BButton variant="outline-secondary" class="ms-2" @click="form.reset(...EVENT_MATCHING_FIELDS)">Reset</BButton>
      </template>
    </BCard>
  </form>

  <form @submit.prevent="submit">
    <BCard class="mb-4">
      <h2 class="h5 mb-3">Wake &amp; sleep times</h2>
      <p class="small text-muted">Set a wake/sleep time per day. Leave both blank for no default sleep block that day.</p>
      <table class="table table-sm">
        <thead>
          <tr><th>Day</th><th>Wake up</th><th>Go to sleep</th></tr>
        </thead>
        <tbody>
          <tr v-for="i in orderedDayIndices" :key="i">
            <td class="align-middle">{{ days[i] }}</td>
            <td><BFormInput v-model="form.availability[i].wake" type="time" size="sm" /></td>
            <td><BFormInput v-model="form.availability[i].sleep" type="time" size="sm" /></td>
          </tr>
        </tbody>
      </table>

      <SleepExceptions :initial="sleepExceptions" />

      <template #footer>
        <BButton type="submit" variant="primary" :disabled="form.processing">Save settings</BButton>
        <BButton variant="outline-secondary" class="ms-2" @click="form.reset('availability')">Reset</BButton>
        <BButton variant="outline-secondary" class="ms-2" @click="clearAvailability">Clear all</BButton>
      </template>
    </BCard>
  </form>

  <form @submit.prevent="submit">
    <BCard class="mb-4">
        <h2 class="h5 mb-3">Public page</h2>
        <p class="small text-muted">
          What a viewer sees at the top of your public share page — separate from your own
          dashboard, and shown to visitors regardless of whether they're logged in anywhere.
        </p>

        <div class="row">
          <div class="col-md-6">
            <BFormGroup label="Page title (English)" label-for="public_page_title_en" class="mb-3">
              <BFormInput
                id="public_page_title_en"
                v-model="form.public_page_title_en"
                type="text"
                :placeholder="`${settings.name}'s Free Time`"
              />
            </BFormGroup>
          </div>
          <div class="col-md-6">
            <BFormGroup label="Page title (Hungarian)" label-for="public_page_title_hu" class="mb-3">
              <BFormInput
                id="public_page_title_hu"
                v-model="form.public_page_title_hu"
                type="text"
                :placeholder="`${settings.name}'s Free Time`"
              />
              <template #description>
                Shown instead of the English title on the Hungarian version of your link — swap
                <code>/free/</code> for <code>/hu/free/</code> in the URL. Leave blank to fall back to
                the English title above.
              </template>
            </BFormGroup>
          </div>
        </div>

        <div class="row">
          <div v-for="colorField in colorFields" :key="colorField.field" class="col-md-4 col-6 mb-3">
            <BFormGroup :label="colorField.label">
              <div class="wtf-swatch-grid">
                <button
                  v-for="swatch in colorPalette"
                  :key="swatch.key"
                  type="button"
                  class="wtf-swatch-btn"
                  :class="{ 'wtf-swatch-btn-active': (form as unknown as Record<string, string>)[colorField.field] === swatch.key }"
                  :aria-pressed="(form as unknown as Record<string, string>)[colorField.field] === swatch.key"
                  :style="{ '--wtf-swatch-light': swatch.light, '--wtf-swatch-dark': swatch.dark }"
                  @click="(form as unknown as Record<string, string>)[colorField.field] = swatch.key"
                  @mouseenter="showSwatchTooltip($event, swatch.label)"
                  @mouseleave="hideSwatchTooltip"
                  @focus="showSwatchTooltip($event, swatch.label)"
                  @blur="hideSwatchTooltip"
                >
                  <span class="visually-hidden">{{ swatch.label }}</span>
                </button>
              </div>
            </BFormGroup>
          </div>
          <div class="col-md-4 col-6 mb-3">
            <BFormGroup label="Current time">
              <div class="wtf-swatch-grid">
                <button
                  v-for="preset in nowColorPresets"
                  :key="preset.key"
                  type="button"
                  class="wtf-swatch-btn"
                  :class="{ 'wtf-swatch-btn-active': form.now_color_key === preset.key }"
                  :style="{ '--wtf-swatch-light': preset.light, '--wtf-swatch-dark': preset.dark }"
                  :aria-pressed="form.now_color_key === preset.key"
                  @click="form.now_color_key = preset.key"
                  @mouseenter="showSwatchTooltip($event, preset.label)"
                  @mouseleave="hideSwatchTooltip"
                  @focus="showSwatchTooltip($event, preset.label)"
                  @blur="hideSwatchTooltip"
                >
                  <span class="visually-hidden">{{ preset.label }}</span>
                </button>
              </div>
              <template #description>
                Deliberately loud, saturated colors kept out of the normal block-color palette
                above, so the current-time line never blends into a same-colored event block. Only
                these presets are available — not a free-form color, so there's no risk of picking
                one that reads badly against one of the themes.
              </template>
            </BFormGroup>
          </div>
        </div>

        <h3 class="h6 mb-3">Icons</h3>
        <div class="row">
          <div v-for="iconField in iconFields" :key="iconField.field" class="col-md-4 col-6 mb-3">
            <BFormGroup :label="iconField.label">
              <div class="wtf-swatch-grid">
                <button
                  v-for="icon in iconPalette"
                  :key="icon.key"
                  type="button"
                  class="wtf-icon-swatch-btn"
                  :class="{ 'wtf-icon-swatch-btn-active': (form as unknown as Record<string, string>)[iconField.field] === icon.key }"
                  :style="{ '--wtf-icon-active-color': activeIconColor(iconField) }"
                  :aria-pressed="(form as unknown as Record<string, string>)[iconField.field] === icon.key"
                  @click="(form as unknown as Record<string, string>)[iconField.field] = icon.key"
                  @mouseenter="showSwatchTooltip($event, icon.label)"
                  @mouseleave="hideSwatchTooltip"
                  @focus="showSwatchTooltip($event, icon.label)"
                  @blur="hideSwatchTooltip"
                >
                  <FontAwesomeIcon v-if="faIconFor(icon.key)" :icon="faIconFor(icon.key)!" />
                  <span class="visually-hidden">{{ icon.label }}</span>
                </button>
              </div>
            </BFormGroup>
          </div>
        </div>

        <BTooltip
          v-if="activeSwatchTarget"
          v-model="tooltipVisible"
          :target="activeSwatchTarget"
          no-fade
          noninteractive
          placement="top"
        >
          {{ activeSwatchLabel }}
        </BTooltip>

        <div class="row">
          <div v-for="theme in (['light', 'dark'] as const)" :key="theme" class="col-md-6 mb-3">
            <div
              class="wtf-pattern-preview-panel wtf-theme-preview"
              :data-bs-theme="theme"
              :style="theme === 'dark' ? previewStyleDark : previewStyleLight"
            >
              <p class="small fw-bold text-uppercase mb-1" :style="{ color: theme === 'dark' ? previewSecondaryColorDark : previewSecondaryColorLight }">
                <FontAwesomeIcon :icon="theme === 'dark' ? faMoon : faSun" class="me-1" />{{ theme === 'dark' ? 'Dark theme' : 'Light theme' }}
              </p>
              <p class="small fw-bold mb-1">
                {{ form.public_page_title_en || `${settings.name}'s Free Time` }}
              </p>
              <p class="small mb-2" :style="{ color: theme === 'dark' ? previewSecondaryColorDark : previewSecondaryColorLight }">
                <template v-if="previewAvailability">
                  A smaller reference for how these colors read together — see the full preview under "Calendar" above for your actual events.
                </template>
                <template v-else>
                  Example calendar — made-up events, just to show how these colors read together. Use "Preview" under "Calendar" above to see your actual calendar there instead.
                </template>
              </p>
              <CalendarView
                :visible-days="colorPreviewVisibleDays"
                :free-slots="(previewAvailability ?? exampleAvailability).free"
                :highlighted-slots="(previewAvailability ?? exampleAvailability).highlighted"
                :unavailable-slots="(previewAvailability ?? exampleAvailability).unavailable"
                :work-slots="(previewAvailability ?? exampleAvailability).work"
                :sleep-slots="(previewAvailability ?? exampleAvailability).sleep"
                :icons="formIcons"
                :pending="false"
                :has-error="false"
                :has-any-free-time="true"
                :timezone="previewAvailability ? form.timezone : 'UTC'"
                :show-blocks="true"
                :show-current-time="true"
                :current-time-pct="currentTimePct"
              />
            </div>
          </div>
        </div>

      <template #footer>
        <BButton type="submit" variant="primary" :disabled="form.processing">Save public page</BButton>
        <BButton variant="outline-secondary" class="ms-2" @click="form.reset(...PUBLIC_PAGE_FIELDS)">Reset</BButton>
      </template>
    </BCard>
  </form>
</template>
