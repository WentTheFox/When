<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import axios from 'axios';
import {
  BAlert,
  BBadge,
  BButton,
  BCard,
  BFormGroup,
  BFormInput,
  BFormSelect,
  BFormTextarea,
} from 'bootstrap-vue-next';
import { addDays as addDaysFns, startOfWeek as startOfWeekFns } from 'date-fns';
import { computed, onUnmounted, ref, watch } from 'vue';
import DashboardLayout from '../../Layouts/DashboardLayout.vue';
import { useLiveThemePreview } from '../../dashboard/liveThemePreview';
import PatternPreview from '../../dashboard/PatternPreview.vue';
import SleepExceptions from '../../dashboard/SleepExceptions.vue';
import CalendarView from '../../free/CalendarView.vue';
import { BLOCK_ALPHA, hexToRgba, hexToRgbTriplet } from '../../free/color-utils';
import { COLOR_PALETTE, DEFAULT_SWATCH_KEY, resolveSwatchHex } from '../../free/color-palette';
import type { ColorSlot } from '../../free/color-palette';
import { useResolvedTheme } from '../../composables/useTheme';
import type { AvailabilityResponse } from '../../free/nuxt-blocks';

defineOptions({ layout: DashboardLayout });

interface Settings {
  timezone: string;
  /** 0=Sunday..6=Saturday, date-fns' own weekStartsOn convention. */
  week_start: number;
  dnd_event_name: string | null;
  nap_event_name: string | null;
  calendar_parsing_mode: 'auto' | 'full_detail' | 'free_busy_only';
  highlight_clause_pattern: string | null;
  activity_clause_pattern: string | null;
  tentative_pattern: string | null;
  public_page_title_en: string | null;
  public_page_title_hu: string | null;
  name: string;
  accent_color_key: string | null;
  secondary_color_key: string | null;
  sleep_color_key: string | null;
  busy_color_key: string | null;
  free_color_key: string | null;
  highlight_color_key: string | null;
  now_color: string | null;
  availability: Record<number, { wake: string | null; sleep: string | null }>;
}

const props = defineProps<{
  settings: Settings;
  defaults: {
    dndEventName: string;
    napEventName: string;
    highlightClausePattern: string;
    activityClausePattern: string;
    tentativePattern: string;
  };
  timezones: string[];
  calendarUrl: string | null;
  sleepExceptions: { id: string; start_date: string; end_date: string; label_ciphertext: string | null }[];
}>();

const days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
const weekStartOptions = days.map((label, value) => ({ value, label }));
/**
 * Each slot picks a swatch KEY from the app's fixed palette (see
 * color-palette.ts) rather than an arbitrary hex — a free-form picker let
 * an owner choose a color that read fine in whichever theme they were
 * previewing and badly in the other (e.g. a light pastel free-block color
 * picked in light mode nearly disappears against dark mode's own dark
 * background); every swatch instead has its own hand-picked light AND dark
 * hex. "Current time" isn't here — it's deliberately theme-independent
 * (see dark-theme.css) and stays a plain hex picker.
 */
const colorFields: { field: keyof Settings; slot: ColorSlot; label: string }[] = [
  { field: 'accent_color_key', slot: 'accent', label: 'Accent' },
  { field: 'secondary_color_key', slot: 'secondary', label: 'Secondary' },
  { field: 'free_color_key', slot: 'free', label: 'Free' },
  { field: 'busy_color_key', slot: 'busy', label: 'Busy' },
  { field: 'sleep_color_key', slot: 'sleep', label: 'Sleep' },
  { field: 'highlight_color_key', slot: 'highlighted', label: 'Highlighted' },
];

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
    const dow = exampleWeekDatesMonFirst[dayOffset % 7]!.getUTCDay();
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
  const busyPeriodsByDay: Record<number, [number, number][]> = {
    0: [[12 * 60, 14 * 60]], // Mon: Lunch with Alice
    1: [[9 * 60, 11 * 60 + 30]], // Tue: Team meeting
    2: [[14 * 60, 16 * 60]], // Wed: Maybe call
    3: [[10 * 60, 12 * 60]], // Thu: Coffee with Bob
    4: [[13 * 60, 17 * 60]], // Fri: Workshop
  };
  const free = Array.from({ length: 7 }, (_, day) => {
    const win = dayWindowMinutes(day);
    const windowStart = win ? win.wakeMin : 0;
    const windowEnd = win ? win.sleepMin : 1440;

    const segments: { start: string; end: string }[] = [];
    let cursor = windowStart;
    for (const [busyStart, busyEnd] of busyPeriodsByDay[day] ?? []) {
      if (busyStart > cursor) segments.push({ start: atAbsMinutes(day * 1440 + cursor), end: atAbsMinutes(day * 1440 + busyStart) });
      cursor = Math.max(cursor, busyEnd);
    }
    if (cursor < windowEnd) segments.push({ start: atAbsMinutes(day * 1440 + cursor), end: atAbsMinutes(day * 1440 + windowEnd) });
    return segments;
  }).flat();

  // Sleep: awake windows per day-offset (0-7, matching the existing
  // wraparound-into-"day 7" need so day 6's late hours still get a block),
  // merged, then inverted across the whole span — same shape as
  // AvailabilityService::computeSleepBlocks.
  const awakeWindows = Array.from({ length: 8 }, (_, day) => {
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
  let cursor = 0;
  for (const window of mergedAwake) {
    if (window.start > cursor) sleep.push({ start: atAbsMinutes(cursor), end: atAbsMinutes(window.start) });
    cursor = Math.max(cursor, window.end);
  }
  if (cursor < 8 * 1440) sleep.push({ start: atAbsMinutes(cursor), end: atAbsMinutes(8 * 1440) });

  return {
    free,
    sleep,
    unavailable: [
      // A highlighted event is still busy time — the real backend always
      // double-lists its range in `unavailable` too, since `highlighted` is
      // an overlay split out of an existing unavailable/free base block
      // (getBlocksForDay's splitByOverlay), not a standalone block of its
      // own. Omitting the base here is exactly what caused the gap/squash.
      { start: at(0, 12), end: at(0, 14) }, // Mon: Lunch with Alice
      { start: at(1, 9), end: at(1, 11, 30) }, // Tue: Team meeting
      { start: at(2, 14), end: at(2, 16), tentative: true }, // Wed: Maybe call
      { start: at(3, 10), end: at(3, 12), tentative: true }, // Thu: Coffee with Bob
      { start: at(4, 13), end: at(4, 17) }, // Fri: Workshop
    ],
    highlighted: [
      { start: at(0, 12), end: at(0, 14), activity: 'Lunch', highlight_words: ['Alice'] }, // Mon
      { start: at(3, 10), end: at(3, 12), activity: 'Coffee', highlight_words: ['Bob'], tentative: true }, // Thu
    ],
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
  dnd_event_name: props.settings.dnd_event_name ?? props.defaults.dndEventName,
  nap_event_name: props.settings.nap_event_name ?? props.defaults.napEventName,
  calendar_parsing_mode: props.settings.calendar_parsing_mode,
  highlight_clause_pattern: props.settings.highlight_clause_pattern ?? props.defaults.highlightClausePattern,
  activity_clause_pattern: props.settings.activity_clause_pattern ?? props.defaults.activityClausePattern,
  tentative_pattern: props.settings.tentative_pattern ?? props.defaults.tentativePattern,
  public_page_title_en: props.settings.public_page_title_en ?? '',
  public_page_title_hu: props.settings.public_page_title_hu ?? '',
  accent_color_key: props.settings.accent_color_key ?? DEFAULT_SWATCH_KEY.accent,
  secondary_color_key: props.settings.secondary_color_key ?? DEFAULT_SWATCH_KEY.secondary,
  free_color_key: props.settings.free_color_key ?? DEFAULT_SWATCH_KEY.free,
  busy_color_key: props.settings.busy_color_key ?? DEFAULT_SWATCH_KEY.busy,
  sleep_color_key: props.settings.sleep_color_key ?? DEFAULT_SWATCH_KEY.sleep,
  highlight_color_key: props.settings.highlight_color_key ?? DEFAULT_SWATCH_KEY.highlighted,
  now_color: props.settings.now_color ?? '#e5566a',
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
const previewResult = ref<{ detected_mode: string; slotCount: number } | null>(null);
/** Set once a real preview fetch succeeds — the calendar preview panel below switches from the synthetic example to this actual data. */
const previewAvailability = ref<AvailabilityResponse | null>(null);
const previewDays = computed(() => {
  const weekStart = startOfWeekFns(new Date(), { weekStartsOn: form.week_start as 0 | 1 | 2 | 3 | 4 | 5 | 6 });
  return Array.from({ length: 7 }, (_, i) => addDaysFns(weekStart, i));
});
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
const previewColorStyle = computed(() => {
  const accent = resolveSwatchHex(form.accent_color_key, 'accent', resolvedTheme.value);
  const free = resolveSwatchHex(form.free_color_key, 'free', resolvedTheme.value);
  const busy = resolveSwatchHex(form.busy_color_key, 'busy', resolvedTheme.value);
  const sleep = resolveSwatchHex(form.sleep_color_key, 'sleep', resolvedTheme.value);
  const highlighted = resolveSwatchHex(form.highlight_color_key, 'highlighted', resolvedTheme.value);
  const alpha = BLOCK_ALPHA[resolvedTheme.value];

  return {
    '--wtf-accent': accent,
    '--wtf-accent-rgb': hexToRgbTriplet(accent),
    '--wtf-color-free': hexToRgba(free, alpha.free),
    '--wtf-hue-free': free,
    '--wtf-color-busy': hexToRgba(busy, alpha.busy),
    '--wtf-color-sleep': hexToRgba(sleep, alpha.sleep),
    '--wtf-hue-sleep': sleep,
    '--wtf-color-highlighted': hexToRgba(highlighted, alpha.highlighted),
    '--wtf-hue-highlighted': highlighted,
    '--wtf-color-now': form.now_color,
  };
});

const previewSecondaryColor = computed(() => resolveSwatchHex(form.secondary_color_key, 'secondary', resolvedTheme.value));

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
      dnd_event_name: form.dnd_event_name,
      nap_event_name: form.nap_event_name,
      highlight_clause_pattern: form.highlight_clause_pattern,
      activity_clause_pattern: form.activity_clause_pattern,
      tentative_pattern: form.tentative_pattern,
      availability_settings: availabilitySettings,
    });

    previewResult.value = {
      detected_mode: data.detected_mode,
      slotCount: data.free.length + data.highlighted.length + data.unavailable.length + data.sleep.length,
    };
    previewAvailability.value = {
      free: data.free,
      highlighted: data.highlighted,
      unavailable: data.unavailable,
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

function resetColor(field: keyof Settings, value: string): void {
  (form as unknown as Record<string, string>)[field] = value;
}

function resetAvailability(): void {
  form.availability = days.map(() => ({ wake: '', sleep: '' }));
}

function submit(): void {
  form.transform((data) => ({
    ...data,
    availability: Object.fromEntries(data.availability.map((day, i) => [i, day])),
  })).patch('/settings', { preserveScroll: true });
}
</script>

<template>
  <BAlert :model-value="!!$page.props.flash?.status" variant="success">{{ $page.props.flash?.status }}</BAlert>

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
        <BFormInput
          id="calendar_url"
          v-model="calendarUrlForm.calendar_url"
          type="url"
          placeholder="https://..."
          @input="onUrlInput"
        />
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
      <span v-if="calendarUrlJustSaved" class="small text-success ms-2">Saved</span>
      <span v-else class="small text-muted ms-2">{{ previewStatus }}</span>

      <div v-if="previewResult" class="mt-3">
        <p class="mb-1"><strong>Detected feed type:</strong> {{ previewResult.detected_mode }}</p>
        <p class="mb-0 text-muted small">{{ previewResult.slotCount }} block(s) computed for the next 14 days.</p>
      </div>

      <div
        v-if="previewAvailability && !calendarUrlJustSaved"
        class="mt-3"
        :style="previewColorStyle"
      >
        <CalendarView
          :visible-days="previewDays"
          :free-slots="previewAvailability.free"
          :highlighted-slots="previewAvailability.highlighted"
          :unavailable-slots="previewAvailability.unavailable"
          :sleep-slots="previewAvailability.sleep"
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
        <option value="auto">Auto-detect</option>
        <option value="full_detail">Full detail (event titles are used for highlighting)</option>
        <option value="free_busy_only">Free/busy only (titles aren't meaningful; use manual tags instead)</option>
      </BFormSelect>
      <template #description>
        Auto-detect looks at your feed once and picks the closest match. Pin it here if it guesses wrong.
      </template>
    </BFormGroup>

    <BFormGroup label="Timezone" label-for="timezone" class="mb-3">
      <BFormSelect id="timezone" v-model="form.timezone">
        <option v-for="tz in timezones" :key="tz" :value="tz">{{ tz }}</option>
      </BFormSelect>
    </BFormGroup>

    <BFormGroup label="Week starts on" label-for="week_start" class="mb-3">
      <BFormSelect id="week_start" v-model.number="form.week_start">
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
        <h2 class="h5 mb-3">Do-not-disturb &amp; naps</h2>

        <BAlert :model-value="true" variant="secondary" class="small">
          <strong>What these text-match fields actually do:</strong> what you type isn't compared
          for an exact match — it's used as the body of a
          <a href="https://developer.mozilla.org/en-US/docs/Web/JavaScript/Guide/Regular_expressions" target="_blank" rel="noopener">regular expression</a>,
          tested case-insensitively against <em>anywhere</em> in the event's title (not anchored
          to the start or the whole string). So a plain word like <code>DND</code> matches a
          title that merely <em>contains</em> "DND" anywhere — "Team DND block" matches just as
          much as a title that's only "DND". If you want to match at the very start of the title
          instead, anchor it yourself with <code>^</code>, e.g. <code>^DND</code>. If what you
          type isn't valid regex syntax, matching just silently never happens (fails closed)
          rather than breaking your page. Leave a field blank to turn that feature off entirely.
        </BAlert>

        <div class="row">
          <div class="col-md-6">
            <BFormGroup label="DND event name/pattern" label-for="dnd_event_name" class="mb-3">
              <BFormInput id="dnd_event_name" v-model="form.dnd_event_name" type="text" :placeholder="defaults.dndEventName" />
              <template #description>A match hides the event entirely from viewers (unless a share link bypasses it).</template>
            </BFormGroup>

            <BFormGroup label="Nap event name/pattern" label-for="nap_event_name" class="mb-3">
              <BFormInput id="nap_event_name" v-model="form.nap_event_name" type="text" :placeholder="defaults.napEventName" />
              <template #description>A match shows the event as sleep instead of busy.</template>
            </BFormGroup>

            <BFormGroup label="Highlight clause pattern (advanced)" label-for="highlight_clause_pattern" class="mb-3">
              <BFormTextarea id="highlight_clause_pattern" v-model="form.highlight_clause_pattern" rows="2" :placeholder="defaults.highlightClausePattern" />
              <template #description>
                Same regex-body rules as above, but everything after "with"/"w/" is captured as a
                whole (to the end of the title), then split on commas — each comma-separated
                piece is checked as a <em>substring</em> (not a whole-word match, and this
                comparison is case-<strong>sensitive</strong>) against a share link's own
                configured highlight words (set per-link, not here). "Dinner with Alice, Bob"
                checks both "Alice" and "Bob" individually. "Host X" also matches, marking the
                event as the calendar owner hosting X; "Visit X" marks the owner visiting X.
              </template>
            </BFormGroup>

            <BFormGroup label="Activity clause pattern (advanced)" label-for="activity_clause_pattern" class="mb-3">
              <BFormTextarea id="activity_clause_pattern" v-model="form.activity_clause_pattern" rows="2" :placeholder="defaults.activityClausePattern" />
              <template #description>
                A separate pattern from the one above — its capture group is the freetext
                <em>before</em> "with"/"w/" (e.g. "Dinner" in "Dinner with Alice"), shown to
                viewers alongside the highlight word by default. Turn this off for an individual
                share link from that link's own settings (not here) if you'd rather it only ever
                show who an event is with, never what it is.
              </template>
            </BFormGroup>

            <BFormGroup label="Tentative title pattern (advanced)" label-for="tentative_pattern" class="mb-3">
              <BFormInput id="tentative_pattern" v-model="form.tentative_pattern" type="text" :placeholder="defaults.tentativePattern" />
              <template #description>
                Same regex-body rules as above. An event whose title matches this (in addition to
                any calendar-provided "tentative" status) is shown to viewers as tentative, and the
                matched text is stripped from the title they see. The default matches a trailing
                <code>(?)</code>, e.g. "Maybe lunch (?)" &rarr; "Maybe lunch". Leave blank to fall
                back to that default rather than turning detection off.
              </template>
            </BFormGroup>
          </div>

          <div class="col-md-6">
            <div class="wtf-pattern-preview-panel">
              <p class="small fw-bold mb-2">Live preview against example titles</p>

              <p class="small text-muted mb-1">DND — <code>{{ form.dnd_event_name || '(blank, off)' }}</code></p>
              <PatternPreview
                :pattern="form.dnd_event_name"
                :examples="['DND', 'Team DND block', 'dnd - focus time', 'Focus time', 'Lunch with Sarah']"
                mode="match"
              />

              <p class="small text-muted mb-1 mt-3">Nap — <code>{{ form.nap_event_name || '(blank, off)' }}</code></p>
              <PatternPreview
                :pattern="form.nap_event_name"
                :examples="['Nap', 'Afternoon nap', 'NAP TIME', 'Sleep', 'Standup meeting']"
                mode="match"
              />

              <p class="small text-muted mb-1 mt-3">
                Highlight clause — <code>{{ form.highlight_clause_pattern || defaults.highlightClausePattern }}</code>
                <br><span class="text-muted">(against sample configured words "Alice", "Bob")</span>
              </p>
              <PatternPreview
                :pattern="form.highlight_clause_pattern || defaults.highlightClausePattern"
                :examples="['Dinner with Alice', 'Call w/ Bob', 'Team sync', 'Dinner with Charlie, Alice, Bob', 'Host Alice', 'Visit Bob']"
                :sample-words="['Alice', 'Bob']"
                mode="tokens"
              />

              <p class="small text-muted mb-1 mt-3">
                Activity clause — <code>{{ form.activity_clause_pattern || defaults.activityClausePattern }}</code>
              </p>
              <PatternPreview
                :pattern="form.activity_clause_pattern || defaults.activityClausePattern"
                :examples="['Dinner with Alice', 'Call w/ Bob', 'Team sync', 'Coffee with Charlie, then gym']"
                mode="extract"
              />

              <p class="small text-muted mb-1 mt-3">
                Tentative title — <code>{{ form.tentative_pattern || defaults.tentativePattern }}</code>
              </p>
              <PatternPreview
                :pattern="form.tentative_pattern || defaults.tentativePattern"
                :examples="['Maybe lunch (?)', 'Team standup', 'Coffee with Alice (?)', 'Workshop']"
                mode="match"
              />
            </div>
          </div>
        </div>

      <template #footer>
        <BButton type="submit" variant="primary" :disabled="form.processing">Save settings</BButton>
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
        <BButton variant="outline-secondary" class="ms-2" @click="resetAvailability">Reset</BButton>
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
                  v-for="swatch in COLOR_PALETTE"
                  :key="swatch.key"
                  type="button"
                  class="wtf-swatch-btn"
                  :class="{ 'wtf-swatch-btn-active': (form as unknown as Record<string, string>)[colorField.field] === swatch.key }"
                  :title="swatch.label"
                  :aria-pressed="(form as unknown as Record<string, string>)[colorField.field] === swatch.key"
                  :style="{ '--wtf-swatch-light': swatch.light, '--wtf-swatch-dark': swatch.dark }"
                  @click="(form as unknown as Record<string, string>)[colorField.field] = swatch.key"
                >
                  <span class="visually-hidden">{{ swatch.label }}</span>
                </button>
              </div>
            </BFormGroup>
          </div>
          <div class="col-md-4 col-6 mb-3">
            <BFormGroup label="Current time">
              <div class="input-group">
                <BFormInput v-model="form.now_color" type="color" />
                <BButton variant="outline-secondary" size="sm" @click="resetColor('now_color', '#e5566a')">
                  Reset
                </BButton>
              </div>
              <template #description>
                Same in both themes — it's a fixed marker color, not tied to a theme's own palette.
              </template>
            </BFormGroup>
          </div>
        </div>

        <div class="wtf-pattern-preview-panel" :style="previewColorStyle">
          <p class="small fw-bold mb-1">
            {{ form.public_page_title_en || `${settings.name}'s Free Time` }}
          </p>
          <p class="small mb-2" :style="{ color: previewSecondaryColor }">
            <template v-if="previewAvailability">
              A smaller reference for how these colors read together — see the full preview under "Calendar" above for your actual events.
            </template>
            <template v-else>
              Example calendar — made-up events, just to show how these colors read together. Use "Preview" under "Calendar" above to see your actual calendar there instead.
            </template>
          </p>
          <CalendarView
            :visible-days="previewAvailability ? previewDays : exampleVisibleDays"
            :free-slots="(previewAvailability ?? exampleAvailability).free"
            :highlighted-slots="(previewAvailability ?? exampleAvailability).highlighted"
            :unavailable-slots="(previewAvailability ?? exampleAvailability).unavailable"
            :sleep-slots="(previewAvailability ?? exampleAvailability).sleep"
            :pending="false"
            :has-error="false"
            :has-any-free-time="true"
            :timezone="previewAvailability ? form.timezone : 'UTC'"
            :show-blocks="true"
            :show-current-time="true"
            :current-time-pct="currentTimePct"
          />
        </div>

      <template #footer>
        <BButton type="submit" variant="primary" :disabled="form.processing">Save public page</BButton>
      </template>
    </BCard>
  </form>
</template>
