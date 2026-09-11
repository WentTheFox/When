<script setup lang="ts">
import { faChevronLeft, faChevronRight, faLock, faRotateRight } from '@fortawesome/free-solid-svg-icons';

/**
 * Vue/Inertia port of the public /free viewer. The crypto and fetch-polling
 * logic is carried over essentially unchanged from the earlier vanilla-TS
 * port — security-sensitive, already-tested code (decrypt flow, key
 * resolution for all three link types). The calendar rendering itself is
 * CalendarView.vue/AgendaView.vue/MonthView.vue, a from-WentTheNuxt port
 * (see CalendarView.vue's header comment) fed AvailabilityResult's flat,
 * tagged event list directly. CalendarView (desktop/week) and AgendaView
 * (mobile) are both always in the DOM and toggle visibility via CSS
 * breakpoint (same as the source app); MonthView replaces CalendarView
 * entirely when the owner switches to month view — AgendaView keeps
 * showing the same week regardless, since a month's worth of agenda rows
 * isn't a useful mobile view.
 */
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import { BButton, BFormSelect } from 'bootstrap-vue-next';
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
import { BLOCK_ALPHA, fcalTextVars, hexToRgba, hexToRgbTriplet, yiqTextColor } from '../../free/color-utils';
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
    public: string | null;
    sleep: string | null;
    highlighted: string | null;
    now: string | null;
  };
  icons: {
    free: string | null;
    busy: string | null;
    work: string | null;
    school: string | null;
    public: string | null;
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
  const publicColor = resolveSwatchHex(props.colors.public, 'public', theme);
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
    '--app-color-public': hexToRgba(publicColor, alpha.public),
    '--app-hue-public': publicColor,
    '--app-color-sleep': hexToRgba(sleep, alpha.sleep),
    '--app-hue-sleep': sleep,
    '--app-color-highlighted': hexToRgba(highlighted, alpha.highlighted),
    '--app-hue-highlighted': highlighted,
    '--app-color-now': resolveNowColorHex(props.colors.now, theme),
    ...fcalTextVars(),
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
  public: resolveIcon(props.icons.public, 'public'),
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
  /** False when the owner has never set a timezone — `timezone` above is still a valid IANA zone (defaults to 'UTC') for rendering, but that's a guess, not a real comparison point, so the match/offset note is suppressed rather than shown against it. */
  timezone_configured: boolean;
}

const showError = ref(false);

// ── Reactive UI state ───────────────────────────────────────────────
const showExpired = ref(false);
const showStatus = ref(true);
const statusText = ref(trans('free.loading'));
const showCalendar = ref(false);
const timezone = ref('UTC');

const availability = ref<AvailabilityResponse>({ events: [] });

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

const hasAnyFreeTime = computed(() => availability.value.events.some(e => e.type === 'free'));

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

interface VisitorTimezoneOption {
  timezone: string;
  count: number;
}

interface VisitorLocaleOption {
  locale: string;
  count: number;
}

/** The viewer's own browser-reported timezone never changes over the page's lifetime, so this is read once, not reactively. */
const viewerBrowserTimezone = Intl.DateTimeFormat().resolvedOptions().timeZone;

/** Set by boot() once the owner's configured timezone is known. */
const ownerTimezoneInfo = ref<{ timezone: string; timezoneConfigured: boolean } | null>(null);

/**
 * Set true by recordVisit() only when its POST comes back 202 — i.e. this
 * view *is* the owner previewing their own link (see
 * ShareLinkVisitController::store). Distinct from
 * `visitorTimezoneOptions.length > 0` because an owner preview with zero
 * recorded visits still needs to fall on the "nothing verified yet" branch
 * below, not silently fall back to comparing the owner's own browser
 * timezone against itself.
 */
const isOwnerPreview = ref(false);

/**
 * Only ever populated when isOwnerPreview is true — every distinct real
 * visitor timezone recorded for this link, with view counts, most-viewed
 * first.
 */
const visitorTimezoneOptions = ref<VisitorTimezoneOption[]>([]);

/** Bound to the owner-only picker below the calendar's timezone note. */
const selectedVisitorTimezone = ref<string | null>(null);

/**
 * Same as visitorTimezoneOptions, but for locale — purely informational
 * (there's no comparison/"match" note for locale), so this is just
 * displayed, never picked from.
 */
const visitorLocaleOptions = ref<VisitorLocaleOption[]>([]);

/**
 * Owner-only, client-side-only toggle (see the alert banner in the
 * template) — lets the owner see this page the way an ordinary visitor
 * would: the timezone-comparison picker, visitor-locales note, and
 * refresh button all disappear, and the offset note (if shown at all)
 * falls back to comparing against the owner's own browser timezone, same
 * as a real visitor's browser would. Never persisted — it's a per-view
 * "preview as a stranger" toggle, not a setting.
 */
const ownerCustomizationsEnabled = ref(true);

/** True while the owner preview's own customizations are actually showing — i.e. this *is* the owner's preview, and they haven't toggled it off. */
const showOwnerCustomizations = computed(() => isOwnerPreview.value && ownerCustomizationsEnabled.value);

/**
 * The timezone this view's note is computed against. For an ordinary
 * (non-owner) viewer, or the owner with customizations toggled off, always
 * the viewer's own browser timezone. For the owner previewing their own
 * link with customizations on, only a real, previously-recorded visitor
 * timezone — never the owner's own browser timezone, which would trivially
 * always "match" and tell the owner nothing.
 */
const comparisonTimezone = computed(() => (showOwnerCustomizations.value ? selectedVisitorTimezone.value : viewerBrowserTimezone));

const timezoneOffsetNote = computed(() => {
  const info = ownerTimezoneInfo.value;
  if (info === null || !info.timezoneConfigured || comparisonTimezone.value === null) {
    return '';
  }

  const now = new Date();
  const viewerOffset = -getTimezoneOffsetMinutes(now, comparisonTimezone.value);
  const ownerOffset = -getTimezoneOffsetMinutes(now, info.timezone);
  const diffMinutes = viewerOffset - ownerOffset;

  if (diffMinutes === 0) {
    return trans('free.timezoneMatch');
  }

  const abs = Math.abs(diffMinutes);
  const hours = Math.floor(abs / 60);
  const minutes = abs % 60;
  const offsetText = `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}`;
  return trans(diffMinutes > 0 ? 'free.timezoneAhead' : 'free.timezoneBehind', { offset: offsetText });
});

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

/** Shared by boot() and the owner's forceRefresh() poll below — decrypts and applies a 'ready' response, without touching the loading/error UI state either of those two callers manage differently. */
async function applyReadyResponse(response: ApiResponse): Promise<void> {
  // response.timezone is a guessed 'UTC' when the owner has never
  // configured one (see ApiResponse['timezone_configured']) — rendering
  // the grid in that guess would misalign it against the viewer's own
  // wall-clock hours for no reason, so render in the viewer's own
  // detected browser timezone instead in that case. This only affects
  // which hours the calendar grid's columns/labels line up with — the
  // underlying computed slots are already fixed instants either way.
  timezone.value = response.timezone_configured
    ? response.timezone
    : viewerBrowserTimezone;
  ownerTimezoneInfo.value = { timezone: response.timezone, timezoneConfigured: response.timezone_configured };

  const key = await resolveContentKey();
  const plaintext = await decryptString(key, response.ciphertext!);
  availability.value = JSON.parse(plaintext) as AvailabilityResponse;
}

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
    await applyReadyResponse(response);
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

/**
 * Fire-and-forget page-view record — timestamp plus the viewer's own
 * browser-reported IANA timezone and locale, so the owner can eyeball
 * timezone/locale spread across a link's viewers from the dashboard. Never
 * blocks/affects the calendar view itself: swallows its own errors.
 *
 * When the response comes back 202 (see ShareLinkVisitController::store),
 * this *is* the owner previewing their own link — its body carries every
 * distinct real visitor timezone recorded for this link instead, which
 * comparisonTimezone/timezoneOffsetNote above react to directly. The
 * highest-viewed one is selected by default; the picker in the template
 * (v-model="selectedVisitorTimezone") lets the owner override that.
 */
async function recordVisit(): Promise<void> {
  if (!props.linkFound || !props.token) return;

  try {
    const response = await axios.post(`/api/share/${props.token}/visits`, {
      timezone: viewerBrowserTimezone,
      locale: Intl.DateTimeFormat().resolvedOptions().locale,
    });

    if (response.status === 202) {
      isOwnerPreview.value = true;
      const options: VisitorTimezoneOption[] = response.data.visitor_timezones ?? [];
      visitorTimezoneOptions.value = options;
      selectedVisitorTimezone.value = options[0]?.timezone ?? null;
      visitorLocaleOptions.value = response.data.visitor_locales ?? [];
    }
  } catch (error) {
    console.error(error);
  }
}

// ── Owner-only: force a cache refresh ───────────────────────────────

/** True while a forceRefresh() dispatch is in flight and its poll loop below is waiting for the recompute to land. */
const refreshing = ref(false);
/** Set on a 429 (still within the cache window) or a poll timeout — cleared on the next attempt. */
const refreshMessage = ref('');

/** How long POST /refresh's own poll loop waits for the recomputed result before giving up — comfortably longer than a real ICS fetch+parse ever takes. */
const REFRESH_POLL_TIMEOUT_MS = 60_000;
const REFRESH_POLL_INTERVAL_MS = 2_000;

/**
 * Lets the owner force a recompute from their own preview instead of
 * waiting out the server's cache TTL — e.g. right after adding a calendar
 * event they want to see reflected immediately. The server itself rejects
 * this with 429 if it was already granted within the cache window (see
 * ShareLinkAvailabilityController::refresh()); this only ever dispatches
 * once per click, then polls the ordinary GET endpoint until the
 * recomputed (non-stale) result lands, applying it in place without
 * disturbing the rest of the page.
 */
async function forceRefresh(): Promise<void> {
  if (!props.token || refreshing.value) return;

  refreshing.value = true;
  refreshMessage.value = '';

  try {
    // validateStatus: true — 429 (still within the cache window) and 422
    // (calendar unconfigured) are expected, meaningful responses here, not
    // failures to throw past.
    const response = await axios.post(`/api/share/${props.token}/refresh`, undefined, {
      validateStatus: () => true,
    });

    if (response.status !== 200) {
      refreshMessage.value = response.status === 429
        ? trans('free.refreshThrottled', { minutes: String(Math.max(1, Math.ceil((response.data.retry_after_seconds ?? 0) / 60))) })
        : trans('free.refreshFailed');
      return;
    }

    const deadline = Date.now() + REFRESH_POLL_TIMEOUT_MS;
    while (Date.now() < deadline) {
      const res = await fetch(`/api/share/${encodeURIComponent(props.token)}`, {
        headers: { Accept: 'application/json' },
      });

      if (res.ok) {
        const data: ApiResponse = await res.json();
        if (data.status === 'ready' && !data.stale) {
          await applyReadyResponse(data);
          return;
        }
      }

      await new Promise((r) => setTimeout(r, REFRESH_POLL_INTERVAL_MS));
    }

    refreshMessage.value = trans('free.refreshTimedOut');
  } catch (error) {
    console.error(error);
    refreshMessage.value = trans('free.refreshFailed');
  } finally {
    refreshing.value = false;
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
  recordVisit();
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

            <!-- Owner-only: recordVisit()'s POST only ever comes back 202
                 (setting isOwnerPreview) when this *is* the owner viewing
                 their own link. The toggle just flips
                 ownerCustomizationsEnabled — every owner-only bit below
                 (picker, locale note, refresh button, and the offset note's
                 comparison target) reacts to that via showOwnerCustomizations. -->
            <div v-if="isOwnerPreview" class="alert alert-info d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
              <span class="small">{{ $t('free.ownerPreviewNotice') }}</span>
              <BButton variant="outline-secondary" size="sm" @click="ownerCustomizationsEnabled = !ownerCustomizationsEnabled">
                {{ ownerCustomizationsEnabled ? $t('free.ownerPreviewDisable') : $t('free.ownerPreviewEnable') }}
              </BButton>
            </div>

            <p class="small text-center text-muted mt-n2 mb-3">
              {{ $t('free.timezoneLocalNote') }}
              <span v-if="timezoneOffsetNote">&bull; {{ timezoneOffsetNote }}</span>
            </p>

            <!-- Owner-only: shown when recordVisit()'s 202 response carries
                 more than one distinct recorded visitor timezone to choose
                 between — a single option is just used directly (see
                 recordVisit()), no picker needed. -->
            <div v-if="showOwnerCustomizations && visitorTimezoneOptions.length > 1" class="d-flex justify-content-center mb-3">
              <BFormSelect
                v-model="selectedVisitorTimezone"
                size="sm"
                style="max-width: 24rem;"
                :aria-label="$t('free.compareTimezoneLabel')"
              >
                <template #first>
                  <option :value="null" disabled>{{ $t('free.compareTimezoneLabel') }}</option>
                </template>
                <option v-for="option in visitorTimezoneOptions" :key="option.timezone" :value="option.timezone">
                  {{ option.timezone }} ({{ option.count }} {{ option.count === 1 ? $t('free.compareTimezoneView') : $t('free.compareTimezoneViews') }})
                </option>
              </BFormSelect>
            </div>

            <!-- Owner-only, purely informational — no picker, since there's
                 no "match" concept for locale the way there is for a
                 timezone offset. -->
            <p v-if="showOwnerCustomizations && visitorLocaleOptions.length" class="small text-center text-muted mb-3">
              {{ $t('free.visitorLocalesLabel') }}
              {{ visitorLocaleOptions.map((o) => `${o.locale} (${o.count})`).join(', ') }}
            </p>

            <!-- Owner-only: same signal as the picker above. -->
            <div v-if="showOwnerCustomizations" class="d-flex flex-column align-items-center mb-3" style="gap: 0.25rem;">
              <BButton variant="outline-secondary" size="sm" :disabled="refreshing" @click="forceRefresh">
                <FontAwesomeIcon :icon="faRotateRight" :spin="refreshing" class="me-2" />
                {{ refreshing ? $t('free.refreshing') : $t('free.refreshNow') }}
              </BButton>
              <span v-if="refreshMessage" class="small text-muted">{{ refreshMessage }}</span>
            </div>

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
                  :events="availability.events"
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
                  :events="availability.events"
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
                :events="availability.events"
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
