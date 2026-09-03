<script setup lang="ts">
/** Settings.vue's "Public page" card — title/colors/icons/current-time-color, all part of the shared `form` Settings.vue owns and saves via its own submit(). */
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome';
import { faMoon, faSun } from '@fortawesome/free-solid-svg-icons';
import { BButton, BCard, BFormGroup, BFormInput, BTooltip } from 'bootstrap-vue-next';
import { addDays as addDaysFns, startOfWeek as startOfWeekFns } from 'date-fns';
import { computed, ref } from 'vue';
import CalendarView from '../free/CalendarView.vue';
import { BLOCK_ALPHA, hexToRgba, hexToRgbTriplet, yiqTextColor } from '../free/color-utils';
import { getColorPalette, resolveSwatchHex } from '../free/color-palette';
import type { ColorSlot } from '../free/color-palette';
import { faIconFor, iconsForSlot, resolveIcon } from '../free/icon-palette';
import type { IconSlot } from '../free/icon-palette';
import { getNowColorPresets, resolveNowColorHex } from '../free/now-color-presets';
import { useResolvedTheme } from '../composables/useTheme';
import { resolveLocalizedText } from '../free/localizedText';
import type { AvailabilityResponse } from '../free/nuxt-blocks';
import LocalizedTextInput from './LocalizedTextInput.vue';
import type { Settings } from './settingsTypes';
import type { SettingsForm } from '../Pages/Dashboard/Settings.vue';

const props = defineProps<{
  form: SettingsForm;
  name: string;
  previewAvailability: AvailabilityResponse | null;
  submit: () => void;
}>();

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
  { field: 'school_color_key', slot: 'school', label: 'School' },
  { field: 'sleep_color_key', slot: 'sleep', label: 'Sleep' },
  { field: 'highlight_color_key', slot: 'highlighted', label: 'Highlighted' },
];

const colorPalette = getColorPalette();

/**
 * Same curated-KEY-not-arbitrary-value idea as colorFields above, for the
 * icon each block type renders on /free — see IconPalette's own doc
 * comment for why the actual FA icon lookup lives client-side only. No
 * accent/secondary equivalents: those aren't block types with their own
 * icon. `colorField` ties each icon slot back to that same slot's own
 * color-key field above — the selected icon in each group is tinted with
 * that group's own configured color (activeIconColor below), not a
 * single generic accent, so the icon picker visually matches the color
 * picker for the same block type right above it.
 */
const iconFields: { field: keyof Settings; slot: IconSlot; colorField: keyof Settings; label: string }[] = [
  { field: 'free_icon_key', slot: 'free', colorField: 'free_color_key', label: 'Free' },
  { field: 'busy_icon_key', slot: 'busy', colorField: 'busy_color_key', label: 'Unavailable' },
  { field: 'work_icon_key', slot: 'work', colorField: 'work_color_key', label: 'Work' },
  { field: 'school_icon_key', slot: 'school', colorField: 'school_color_key', label: 'School' },
  { field: 'sleep_icon_key', slot: 'sleep', colorField: 'sleep_color_key', label: 'Sleep' },
  { field: 'highlight_icon_key', slot: 'highlighted', colorField: 'highlight_color_key', label: 'Highlighted' },
];

const nowColorPresets = getNowColorPresets();

const resolvedTheme = useResolvedTheme();

/** Resolved against the settings page's own live theme — same as every other color-key resolution on this page. */
function activeIconColor(iconField: (typeof iconFields)[number]): string {
  const colorKey = (props.form as unknown as Record<string, string>)[iconField.colorField];

  return resolveSwatchHex(colorKey, iconField.slot, resolvedTheme.value);
}

/**
 * One shared tooltip for every swatch across every color-slot group,
 * instead of a separate v-b-tooltip instance per circle — with ~21
 * swatches per group across several groups, that was well over a hundred
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
// event falls on never changes) — built as UTC midnight (Date.UTC), not
// local midnight + toISOString-style drift, since this mock is fed
// :timezone="'UTC'" below and reads each day via TZDate(day, 'UTC'): a
// local-midnight Date in any non-UTC environment (e.g. local Monday 00:00
// in UTC+2 is 22:00 Sunday UTC) would silently display one weekday early.
const exampleWeekDatesMonFirst = Array.from({ length: 7 }, (_, i) => {
  const dow = (exampleDay.getDay() + 6) % 7; // Monday = 0
  return new Date(Date.UTC(exampleDay.getFullYear(), exampleDay.getMonth(), exampleDay.getDate() - dow + i));
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
 * form.availability), mirroring AvailabilityService's own dayWindow()/
 * computeSleepBlocks() logic client-side: a day contributes a wake→sleep
 * window only when *both* times are filled in for that day — otherwise
 * it's treated as fully awake all day, same as the real backend, and no
 * sleep entry appears for it at all (never a partial one from just one
 * side being set).
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
    const config = props.form.availability[dow];
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
  // day's own wake/sleep window.
  const events: { day: number; start: number; end: number; tentativeStart?: boolean; tentativeEnd?: boolean; activity?: string; highlightWords?: string[]; work?: boolean; school?: boolean }[] = [
    { day: 0, start: 9 * 60, end: 10 * 60 + 30, school: true }, // Mon: Chemistry class (school)
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
  // for the sleep wraparound below) than that. Both ends are simply
  // eventless — day -1/day 7 render as fully free/asleep per that day's
  // own window, same as any other day with no events on it.
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
    // Same double-bookkeeping as work above.
    school: events
      .filter((event) => event.school)
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

const previewDays = computed(() => {
  const weekStart = startOfWeekFns(new Date(), { weekStartsOn: props.form.week_start as 0 | 1 | 2 | 3 | 4 | 5 | 6 });
  return Array.from({ length: 7 }, (_, i) => addDaysFns(weekStart, i));
});
/**
 * The light/dark dual color-slot preview only shows 3 days — plenty to
 * judge how the colors read, and two side-by-side 7-day calendars would be
 * cramped at half page width each. The synthetic-example branch centers
 * on today (colorPreviewExampleDays) so the current-time indicator is
 * always visible regardless of what day of the week it is; the real-
 * fetched-preview branch (once the Calendar card's own Preview has run)
 * still takes the first 3 days of the week instead — CalendarPreview
 * Controller's own computed range starts at today and only goes forward,
 * so there's no "yesterday" data to center on there even if this centered
 * the same way.
 */
const colorPreviewVisibleDays = computed(() => (props.previewAvailability ? previewDays.value.slice(0, 3) : colorPreviewExampleDays.value));
const currentTimePct = (() => {
  const now = new Date();
  return ((now.getHours() * 60 + now.getMinutes()) / 1440) * 100;
})();

/**
 * <input type="color"> only ever gives a solid hex, no alpha — binding it
 * straight to --app-color-* would make these preview blocks fully opaque,
 * losing the transparent-wash treatment every block gets on the real /free
 * page (see color-utils.ts). Re-applies the same alpha so what's previewed
 * here actually matches what a viewer would see. Same helper as
 * SettingsCalendarCard.vue's own previewStyleFor — duplicated rather than
 * hoisted to a shared composable since each card only needs its own call
 * (a fixed light/dark pair here, the page's own live theme there).
 */
function previewStyleFor(theme: 'light' | 'dark') {
  const accent = resolveSwatchHex(props.form.accent_color_key, 'accent', theme);
  const free = resolveSwatchHex(props.form.free_color_key, 'free', theme);
  const busy = resolveSwatchHex(props.form.busy_color_key, 'busy', theme);
  const work = resolveSwatchHex(props.form.work_color_key, 'work', theme);
  const school = resolveSwatchHex(props.form.school_color_key, 'school', theme);
  const sleep = resolveSwatchHex(props.form.sleep_color_key, 'sleep', theme);
  const highlighted = resolveSwatchHex(props.form.highlight_color_key, 'highlighted', theme);
  const alpha = BLOCK_ALPHA[theme];

  return {
    '--app-accent': accent,
    '--app-accent-rgb': hexToRgbTriplet(accent),
    '--app-accent-text': yiqTextColor(accent),
    '--app-color-free': hexToRgba(free, alpha.free),
    '--app-hue-free': free,
    '--app-color-busy': hexToRgba(busy, alpha.busy),
    '--app-color-work': hexToRgba(work, alpha.work),
    '--app-hue-work': work,
    '--app-color-school': hexToRgba(school, alpha.school),
    '--app-hue-school': school,
    '--app-color-sleep': hexToRgba(sleep, alpha.sleep),
    '--app-hue-sleep': sleep,
    '--app-color-highlighted': hexToRgba(highlighted, alpha.highlighted),
    '--app-hue-highlighted': highlighted,
    '--app-color-now': resolveNowColorHex(props.form.now_color_key, theme),
  };
}

// Both themes are always previewed side by side (see wtf-theme-preview in
// dark-theme.css — a self-contained, scoped copy of the :root[data-bs-
// theme] variable blocks) rather than only the one the settings page
// itself is currently rendered in, so an owner can see how a color choice
// reads on the theme they're NOT currently looking at without switching.
const previewStyleLight = computed(() => previewStyleFor('light'));
const previewStyleDark = computed(() => previewStyleFor('dark'));
const previewSecondaryColorLight = computed(() => resolveSwatchHex(props.form.secondary_color_key, 'secondary', 'light'));
const previewSecondaryColorDark = computed(() => resolveSwatchHex(props.form.secondary_color_key, 'secondary', 'dark'));

/** Fed to this card's own preview CalendarView instances — icons aren't theme-reactive (see icon-palette.ts), so this is a single computed, not a light/dark pair. */
const formIcons = computed(() => ({
  free: resolveIcon(props.form.free_icon_key, 'free'),
  busy: resolveIcon(props.form.busy_icon_key, 'busy'),
  work: resolveIcon(props.form.work_icon_key, 'work'),
  school: resolveIcon(props.form.school_icon_key, 'school'),
  sleep: resolveIcon(props.form.sleep_icon_key, 'sleep'),
  highlighted: resolveIcon(props.form.highlight_icon_key, 'highlighted'),
}));

/** This card's own "Reset" button field list — form.reset(...) only reverts the fields named, not the other cards' worth that happen to share this same useForm() instance. */
const PUBLIC_PAGE_FIELDS = [
  'public_page_title',
  'accent_color_key', 'secondary_color_key', 'free_color_key', 'busy_color_key', 'work_color_key', 'school_color_key', 'sleep_color_key', 'highlight_color_key',
  'free_icon_key', 'busy_icon_key', 'work_icon_key', 'school_icon_key', 'sleep_icon_key', 'highlight_icon_key',
  'now_color_key',
] as const;
</script>

<template>
  <form @submit.prevent="submit">
    <BCard class="mb-4">
        <h2 class="h5 mb-3">Public page</h2>
        <p class="small text-muted">
          What a viewer sees at the top of your public share page — separate from your own
          dashboard, and shown to visitors regardless of whether they're logged in anywhere.
        </p>

        <div class="row">
          <div class="col-md-6">
            <LocalizedTextInput
              v-model="form.public_page_title"
              id="public_page_title"
              label="Page title"
              :default-placeholder="`${name}'s Free Time`"
            />
            <p class="small text-muted mt-n2">
              The default is shown on every locale (e.g. <code>/free/</code>) without its own
              "Add language" override — add one (e.g. <code>hu</code> for <code>/hu/free/</code>)
              only for a locale you want a genuinely different title on. Leave the default blank
              to fall back to "{{ name }}'s Free Time".
            </p>
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
                  :style="{ '--app-swatch-light': swatch.light, '--app-swatch-dark': swatch.dark }"
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
                  :style="{ '--app-swatch-light': preset.light, '--app-swatch-dark': preset.dark }"
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
                above, to avoid the current-time line blending into a same-colored event block.
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
                  v-for="icon in iconsForSlot(iconField.slot)"
                  :key="icon.key"
                  type="button"
                  class="wtf-icon-swatch-btn"
                  :class="{ 'wtf-icon-swatch-btn-active': (form as unknown as Record<string, string>)[iconField.field] === icon.key }"
                  :style="{ '--app-icon-active-color': activeIconColor(iconField) }"
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
                {{ resolveLocalizedText(form.public_page_title, 'default') || `${name}'s Free Time` }}
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
                :school-slots="(previewAvailability ?? exampleAvailability).school"
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
