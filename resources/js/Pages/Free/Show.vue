<script setup lang="ts">
import { faChevronLeft, faChevronRight, faLock } from '@fortawesome/free-solid-svg-icons';

/**
 * Vue/Inertia port of the public /free viewer. The crypto and fetch-polling
 * logic is carried over essentially unchanged from the earlier vanilla-TS
 * port — security-sensitive, already-tested code (decrypt flow, key
 * resolution for all three link types). The calendar rendering itself is
 * CalendarView.vue/AgendaView.vue/MonthView.vue, a from-WentTheNuxt port
 * (see CalendarView.vue's header comment) fed the new AvailabilityResult
 * four-array shape directly. CalendarView (desktop/week) and AgendaView
 * (mobile) are both always in the DOM and toggle visibility via CSS
 * breakpoint (same as the source app); MonthView replaces CalendarView
 * entirely when the owner switches to month view — AgendaView keeps
 * showing the same week regardless, since a month's worth of agenda rows
 * isn't a useful mobile view.
 */
import { Head } from '@inertiajs/vue3';
import { BButton } from 'bootstrap-vue-next';
import {
  addDays as addDaysFns,
  addMonths,
  eachDayOfInterval,
  endOfMonth,
  startOfMonth,
  startOfWeek as startOfWeekFns,
} from 'date-fns';
import { currentLocale, loadLanguageAsync, trans } from 'laravel-vue-i18n';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { decryptString, DecryptionFailedError, deriveHighlightTokenKey } from '../../crypto';
import SiteFooter from '../../Components/SiteFooter.vue';
import SiteHeader from '../../Components/SiteHeader.vue';
import CalendarView from '../../free/CalendarView.vue';
import AgendaView from '../../free/AgendaView.vue';
import MonthView from '../../free/MonthView.vue';
import { BLOCK_ALPHA, hexToRgba, hexToRgbTriplet, yiqTextColor } from '../../free/color-utils';
import { resolveSwatchHex } from '../../free/color-palette';
import { resolveIcon } from '../../free/icon-palette';
import { useResolvedTheme } from '../../composables/useTheme';
import { rememberInviteCode } from '../../composables/useInviteCode';
import { resolveNowColorHex } from '../../free/now-color-presets';
import type { AvailabilityResponse } from '../../free/nuxt-blocks';
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome';

const props = defineProps<{
  token: string | null;
  /** False for a bare /free visit or a token that doesn't resolve to any share link — see ShareLinkController::render()'s doc comment. */
  linkFound: boolean;
  inviteCode: string | null;
  ownerName: string | null;
  pageTitle: string | null;
  locale: string;
  textDirection: 'ltr' | 'rtl';
  /** 0=Sunday..6=Saturday, owner-configurable (Settings), default Monday. */
  weekStart: number;
  colors: {
    accent: string | null;
    secondary: string | null;
    free: string | null;
    busy: string | null;
    work: string | null;
    school: string | null;
    sleep: string | null;
    highlighted: string | null;
    now: string | null;
  };
  icons: {
    free: string | null;
    busy: string | null;
    work: string | null;
    school: string | null;
    sleep: string | null;
    highlighted: string | null;
  };
}>();

// Runs before SiteFooter's own setup (child components mount after this
// component's setup body finishes), so its "Create your own calendar" link
// already sees this on this very page, not just after navigating away.
if (props.inviteCode) rememberInviteCode(props.inviteCode);

const resolvedTheme = useResolvedTheme();

const rootStyle = computed(() => {
  const theme = resolvedTheme.value;
  const accent = resolveSwatchHex(props.colors.accent, 'accent', theme);
  const secondary = resolveSwatchHex(props.colors.secondary, 'secondary', theme);
  const free = resolveSwatchHex(props.colors.free, 'free', theme);
  const busy = resolveSwatchHex(props.colors.busy, 'busy', theme);
  const work = resolveSwatchHex(props.colors.work, 'work', theme);
  const school = resolveSwatchHex(props.colors.school, 'school', theme);
  const sleep = resolveSwatchHex(props.colors.sleep, 'sleep', theme);
  const highlighted = resolveSwatchHex(props.colors.highlighted, 'highlighted', theme);
  const alpha = BLOCK_ALPHA[theme];

  return {
    '--app-accent': accent,
    '--app-accent-rgb': hexToRgbTriplet(accent),
    '--app-accent-text': yiqTextColor(accent),
    '--app-text-muted': secondary,
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
    '--app-color-now': resolveNowColorHex(props.colors.now, theme),
  };
});

// Icon shape doesn't vary by theme (only the block's own text color does,
// already handled by rootStyle above) — a flat, non-theme-reactive
// resolution, unlike rootStyle's color computed.
const resolvedIcons = computed(() => ({
  free: resolveIcon(props.icons.free, 'free'),
  busy: resolveIcon(props.icons.busy, 'busy'),
  work: resolveIcon(props.icons.work, 'work'),
  school: resolveIcon(props.icons.school, 'school'),
  sleep: resolveIcon(props.icons.sleep, 'sleep'),
  highlighted: resolveIcon(props.icons.highlighted, 'highlighted'),
}));

class LinkExpiredError extends Error {
}

/** The owner hasn't set a calendar URL — nothing will ever compute for this link until they do, so this is a terminal state, not "still loading." */
class CalendarUnconfiguredError extends Error {
}

interface ApiResponse {
  status: 'pending' | 'ready' | 'unconfigured';
  ciphertext?: string;
  computed_range_start?: string;
  computed_range_end?: string;
  stale?: boolean;
  timezone: string;
}

const showError = ref(false);

// ── Reactive UI state ───────────────────────────────────────────────
const showExpired = ref(false);
const showStatus = ref(true);
const statusText = ref(trans('free.loading'));
const showCalendar = ref(false);
const timezoneOffsetNote = ref('');
const timezone = ref('UTC');

const availability = ref<AvailabilityResponse>({
  free: [],
  highlighted: [],
  unavailable: [],
  work: [],
  school: [],
  sleep: [],
});

function parseViewParam(): 'week' | 'month' {
  return new URLSearchParams(location.search).get('view') === 'month' ? 'month' : 'week';
}

/**
 * anchorDate is always kept snapped to the start of its own mode's period
 * (the configured first-day-of-week for week mode, the 1st for month mode)
 * — never an arbitrary day within it. Two things that needs: the `at` URL
 * param staying meaningful (it's always "the period's first day," matching
 * what's actually shown, instead of whatever day happened to be clicked or
 * "today" landed on), and addMonths() never clamping across the month-mode
 * boundary — addMonths(Jan 31, 1) silently becomes Feb 28 (Feb 31 doesn't
 * exist), and from there addMonths(Feb 28, 1) is Mar 28, not Mar 31,
 * permanently losing the original day and — combined with switching back
 * to week mode mid-drift — was the "switching view has a chance to land
 * in the past" bug: an unsnapped anchor could end up on a day whose own
 * startOfWeek/startOfMonth no longer agreed with what isAtStart last
 * guarded against. Snapping before every mode's own date math keeps
 * anchorDate always canonical, so there's nothing left to drift.
 */
function snapToPeriodStart(date: Date, mode: 'week' | 'month', weekStart: number): Date {
  return mode === 'month'
    ? startOfMonth(date)
    : startOfWeekFns(date, { weekStartsOn: weekStart as 0 | 1 | 2 | 3 | 4 | 5 | 6 });
}

// A bookmarked/shared URL with a stale `at` from before today is clamped
// back to today rather than honored — same reasoning as goPrev's isAtStart
// guard below: this viewer should never land on a week/month that's
// entirely in the past.
function parseAtParam(viewMode: 'week' | 'month', weekStart: number): Date | null {
  const at = new URLSearchParams(location.search).get('at');
  if (!at || !/^\d{4}-\d{2}-\d{2}$/.test(at)) return null;
  const [y, m, d] = at.split('-').map(Number);
  const parsed = snapToPeriodStart(new Date(y, m - 1, d), viewMode, weekStart);
  const today = snapToPeriodStart(new Date(), viewMode, weekStart);

  return parsed < today ? null : parsed;
}

const viewMode = ref<'week' | 'month'>(parseViewParam());
const anchorDate = ref(parseAtParam(viewMode.value, props.weekStart) ?? snapToPeriodStart(new Date(), viewMode.value, props.weekStart));

function formatDateParam(d: Date): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function updateUrl(): void {
  const params = new URLSearchParams(location.search);
  params.set('view', viewMode.value);
  params.set('at', formatDateParam(anchorDate.value));
  history.replaceState(null, '', `${location.pathname}?${params.toString()}${location.hash}`);
}

// The week AgendaView (mobile) always shows, regardless of viewMode.
const weekDays = computed(() => {
  const weekStart = startOfWeekFns(anchorDate.value, { weekStartsOn: props.weekStart as 0 | 1 | 2 | 3 | 4 | 5 | 6 });
  return Array.from({ length: 7 }, (_, i) => addDaysFns(weekStart, i));
});

const monthDays = computed(() => eachDayOfInterval({
  start: startOfMonth(anchorDate.value),
  end: endOfMonth(anchorDate.value),
}));

// CalendarView/MonthView (desktop) switch between the two based on viewMode.
const visibleDays = computed(() => (viewMode.value === 'week' ? weekDays.value : monthDays.value));

// toLocaleDateString(undefined, ...) uses the *browser's* own language
// setting, not this page's locale — on the hu path that silently kept
// rendering this label in whatever language the viewer's browser/OS
// happened to be in, same class of bug as app.ts's boot-locale fix.
// Intl's BCP-47 tags don't take laravel-vue-i18n's bare 'hu'/'en' as-is.
const intlLocaleTag = computed(() => (currentLocale.value === 'hu' ? 'hu-HU' : 'en-US'));

const navLabel = computed(() => {
  const tag = intlLocaleTag.value;
  if (viewMode.value === 'month') {
    return anchorDate.value.toLocaleDateString(tag, { month: 'long', year: 'numeric' });
  }
  const start = weekDays.value[0]!;
  const end = weekDays.value[6]!;
  return `${start.toLocaleDateString(tag, {
    month: 'short',
    day: 'numeric',
  })} – ${end.toLocaleDateString(tag, { month: 'short', day: 'numeric', year: 'numeric' })}`;
});

const hasAnyFreeTime = computed(() => availability.value.free.length > 0);

const now = ref(new Date());
const currentTimePct = computed(() => ((now.value.getHours() * 60 + now.value.getMinutes()) / 1440) * 100);

// Never navigable earlier than the week/month containing "now" — a viewer
// has no reason to browse a friend's already-elapsed availability, and this
// is what actually prevents the Prev button (and a hand-edited `at` URL,
// via parseAtParam above) from going back into the past.
const isAtStart = computed(() => (viewMode.value === 'month'
  ? startOfMonth(anchorDate.value).getTime() <= startOfMonth(now.value).getTime()
  : startOfWeekFns(anchorDate.value, { weekStartsOn: props.weekStart as 0 | 1 | 2 | 3 | 4 | 5 | 6 }).getTime()
    <= startOfWeekFns(now.value, { weekStartsOn: props.weekStart as 0 | 1 | 2 | 3 | 4 | 5 | 6 }).getTime()));

function goPrev(): void {
  if (isAtStart.value) return;
  anchorDate.value = viewMode.value === 'week' ? addDaysFns(anchorDate.value, -7) : addMonths(anchorDate.value, -1);
  updateUrl();
}

function goNext(): void {
  anchorDate.value = viewMode.value === 'week' ? addDaysFns(anchorDate.value, 7) : addMonths(anchorDate.value, 1);
  updateUrl();
}

function goToday(): void {
  anchorDate.value = snapToPeriodStart(new Date(), viewMode.value, props.weekStart);
  updateUrl();
}

// Re-snapping to the *new* mode's own period start (not just toggling
// viewMode) is what keeps this from ever landing on the past: anchorDate
// was already canonical for the mode being left, but "canonical for week
// mode" and "canonical for month mode" aren't the same day, and leaving it
// as-is here is exactly the drift snapToPeriodStart's own doc comment
// above describes.
function setViewWeek(): void {
  anchorDate.value = snapToPeriodStart(anchorDate.value, 'week', props.weekStart);
  viewMode.value = 'week';
  updateUrl();
}

function setViewMonth(): void {
  anchorDate.value = snapToPeriodStart(anchorDate.value, 'month', props.weekStart);
  viewMode.value = 'month';
  updateUrl();
}

function onWeekClick(day: Date): void {
  anchorDate.value = snapToPeriodStart(day, 'week', props.weekStart);
  viewMode.value = 'week';
  updateUrl();
}

// ── Timezone comparison ─────────────────────────────────────────────

function renderTimezoneOffsetNote(ownerTimezone: string): void {
  const viewerTimezone = Intl.DateTimeFormat().resolvedOptions().timeZone;

  const now = new Date();
  const viewerOffset = -getTimezoneOffsetMinutes(now, viewerTimezone);
  const ownerOffset = -getTimezoneOffsetMinutes(now, ownerTimezone);
  const diffMinutes = viewerOffset - ownerOffset;

  if (diffMinutes === 0) {
    timezoneOffsetNote.value = trans('free.timezoneMatch');
    return;
  }

  const abs = Math.abs(diffMinutes);
  const hours = Math.floor(abs / 60);
  const minutes = abs % 60;
  const offsetText = `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}`;
  timezoneOffsetNote.value = trans(diffMinutes > 0 ? 'free.timezoneAhead' : 'free.timezoneBehind', { offset: offsetText });
}

function getTimezoneOffsetMinutes(date: Date, timeZone: string): number {
  const dtf = new Intl.DateTimeFormat('en-US', {
    timeZone,
    hourCycle: 'h23',
    year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', minute: '2-digit', second: '2-digit',
  });
  const parts = Object.fromEntries(dtf.formatToParts(date).map((p) => [p.type, p.value]));
  const asUtc = Date.UTC(
    Number(parts.year), Number(parts.month) - 1, Number(parts.day),
    Number(parts.hour), Number(parts.minute), Number(parts.second),
  );
  return (asUtc - date.getTime()) / 60000;
}

// ── Decryption ───────────────────────────────────────────────────────

/**
 * Every share link's key derives deterministically from its own URL token
 * — see HighlightTokenKey. Only ever called when linkFound is true (see
 * boot()'s early return above), so token is guaranteed non-null here even
 * though its prop type stays nullable for the no-link-found case.
 */
function resolveContentKey(): Promise<CryptoKey> {
  return deriveHighlightTokenKey(props.token!);
}

async function fetchWithPolling(): Promise<ApiResponse> {
  for (; ;) {
    const res = await fetch(`/api/share/${encodeURIComponent(props.token!)}`, {
      headers: { Accept: 'application/json' },
    });

    if (res.status === 401) {
      throw new LinkExpiredError();
    }

    if (!res.ok) {
      throw new Error(`Request failed: ${res.status}`);
    }

    const data: ApiResponse = await res.json();

    if (data.status === 'ready') {
      return data;
    }

    if (data.status === 'unconfigured') {
      throw new CalendarUnconfiguredError();
    }

    statusText.value = trans('free.fetchingFirstTime');
    await new Promise((r) => setTimeout(r, 2000));
  }
}

// ── Bootstrap ────────────────────────────────────────────────────────

async function boot(): Promise<void> {
  // No share link to fetch at all (a bare /free visit, or a token that
  // never resolved — see ShareLinkController::render()'s doc comment) —
  // show the expired state directly rather than round-tripping to
  // /api/share/{token}, which would only ever 404 for a token this app
  // never even looked up.
  if (!props.linkFound) {
    showExpired.value = true;
    return;
  }

  showCalendar.value = true;
  showStatus.value = true;
  statusText.value = trans('free.loading');

  try {
    const response = await fetchWithPolling();
    timezone.value = response.timezone;
    renderTimezoneOffsetNote(response.timezone);

    const key = await resolveContentKey();
    const plaintext = await decryptString(key, response.ciphertext!);
    availability.value = JSON.parse(plaintext) as AvailabilityResponse;

    showStatus.value = false;
  } catch (error) {
    if (error instanceof LinkExpiredError) {
      showExpired.value = true;
      return;
    }

    showCalendar.value = false;
    showStatus.value = true;
    showError.value = true;
    statusText.value = error instanceof CalendarUnconfiguredError
      ? trans('free.unconfigured')
      : error instanceof DecryptionFailedError
        ? trans('free.decryptFailed')
        : trans('free.loadFailed');
  }
}

onMounted(() => {
  // /free vs /hu/free decides the whole page's language, not just pageTitle
  // — block labels, durations, date-fns weekday/month names all react to
  // this too (CalendarView.vue/AgendaView.vue/MonthView.vue already key off
  // laravel-vue-i18n's currentLocale). app.ts already installs i18nVue with
  // this exact page's own locale at boot, so loadLanguageAsync here is
  // normally a same-language no-op — kept as a defensive fire-and-forget in
  // case this component is ever reached without a full page load.
  if (props.locale !== 'en') {
    loadLanguageAsync(props.locale).catch((e) => console.error(e));
  }

  boot();
  const timer = setInterval(() => {
    now.value = new Date();
  }, 30_000);
  onUnmounted(() => clearInterval(timer));
});
</script>

<template>
  <Head :title="pageTitle ?? $t('free.linkExpiredTitle')" />

  <div class="wtf-backdrop" :style="rootStyle" :dir="textDirection">
    <SiteHeader />

    <div class="wtf-page-content container py-4">
      <div class="card mx-auto" style="max-width: 60rem;">
        <div class="card-body p-4">
          <template v-if="linkFound">
            <h1 class="mb-1 text-center">{{ pageTitle }}</h1>
            <p class="small text-center text-muted mt-n2 mb-3">
              {{ $t('free.timezoneLocalNote') }}
              <span v-if="timezoneOffsetNote">&bull; {{ timezoneOffsetNote }}</span>
            </p>
            <p  class="small text-center text-warning mb-3">
          <FontAwesomeIcon :icon="faLock" class="me-2" />{{ $t('free.personalizedWarning') }}
            </p>
          </template>

          <div v-if="showExpired" class="text-center py-5">
            <h2 class="h4 mb-3">{{ $t('free.linkExpiredTitle') }}</h2>
            <p class="mb-0 text-muted">{{ $t('free.linkExpiredBody') }}</p>
          </div>

          <div v-else>
            <div
              class="d-flex flex-wrap align-items-center justify-content-between mb-3"
              style="gap: 0.5rem;"
            >
              <div class="d-flex flex-wrap align-items-center justify-content-center">
                <BButton
                  variant="outline-secondary"
                  size="sm"
                  :aria-label="viewMode === 'month' ? $t('free.prevMonth') : $t('free.prevWeek')"
                  :disabled="isAtStart"
                  @click="goPrev"
                ><FontAwesomeIcon :icon="faChevronLeft" /></BButton>
                <span class="fw-bold text-center" style="min-width: 12rem;">{{ navLabel }}</span>
                <BButton
                  variant="outline-secondary"
                  size="sm"
                  :aria-label="viewMode === 'month' ? $t('free.nextMonth') : $t('free.nextWeek')"
                  @click="goNext"
                ><FontAwesomeIcon :icon="faChevronRight" /></BButton>
                <BButton variant="secondary" size="sm" class="ms-2" :disabled="isAtStart" @click="goToday">{{ $t('free.today') }}</BButton>
              </div>
              <div class="btn-group ms-2" role="group">
                <BButton
                  size="sm"
                  :variant="viewMode === 'month' ? 'secondary' : 'outline-secondary'"
                  @click="setViewMonth"
                >
                  {{ $t('free.monthView') }}
                </BButton>
                <BButton
                  size="sm"
                  :variant="viewMode === 'week' ? 'secondary' : 'outline-secondary'"
                  @click="setViewWeek"
                >
                  {{ $t('free.weekView') }}
                </BButton>
              </div>
            </div>

            <div v-if="!showCalendar" class="text-center text-muted py-5">
              <span>{{ statusText }}</span>
            </div>

            <template v-else>
              <p v-if="showStatus" class="small text-center text-muted mb-2">
                <span
                  class="spinner-border spinner-border-sm me-2"
                  role="status"
                  aria-hidden="true"
                ></span>{{ statusText }}
              </p>

              <div class="wtf-desktop-only">
                <CalendarView
                  v-if="viewMode === 'week'"
                  :visible-days="visibleDays"
                  :free-slots="availability.free"
                  :highlighted-slots="availability.highlighted"
                  :unavailable-slots="availability.unavailable"
                  :work-slots="availability.work"
                  :school-slots="availability.school"
                  :sleep-slots="availability.sleep"
                  :icons="resolvedIcons"
                  :pending="showStatus"
                  :has-error="showError"
                  :has-any-free-time="hasAnyFreeTime"
                  :timezone="timezone"
                  :show-blocks="true"
                  :show-current-time="true"
                  :current-time-pct="currentTimePct"
                />
                <MonthView
                  v-else
                  :days="visibleDays"
                  :free-slots="availability.free"
                  :highlighted-slots="availability.highlighted"
                  :unavailable-slots="availability.unavailable"
                  :work-slots="availability.work"
                  :school-slots="availability.school"
                  :sleep-slots="availability.sleep"
                  :pending="showStatus"
                  :has-error="showError"
                  :has-any-free-time="hasAnyFreeTime"
                  :timezone="timezone"
                  :show-blocks="true"
                  :show-current-time="true"
                  :current-time-pct="currentTimePct"
                  :week-start="props.weekStart"
                  @week-click="onWeekClick"
                />
              </div>
              <AgendaView
                :days="weekDays"
                :free-slots="availability.free"
                :highlighted-slots="availability.highlighted"
                :unavailable-slots="availability.unavailable"
                :work-slots="availability.work"
                :school-slots="availability.school"
                :sleep-slots="availability.sleep"
                :icons="resolvedIcons"
                :pending="showStatus"
                :has-error="showError"
                :timezone="timezone"
                :show-blocks="true"
                :show-current-time="true"
                :current-time-pct="currentTimePct"
              />
            </template>
          </div>
        </div>
      </div>
    </div>

    <SiteFooter/>
  </div>
</template>
