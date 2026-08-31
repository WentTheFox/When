<script setup lang="ts">
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
import { BButton, BFormInput } from 'bootstrap-vue-next';
import { addDays as addDaysFns, addMonths, eachDayOfInterval, endOfMonth, startOfMonth, startOfWeek as startOfWeekFns } from 'date-fns';
import { loadLanguageAsync } from 'laravel-vue-i18n';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import {
  decryptString,
  DecryptionFailedError,
  deriveLegacyShareLinkKey,
  importKeyFromFragment,
  unwrapKeyWithPassphrase,
} from '../../crypto';
import ThemeToggle from '../../Components/ThemeToggle.vue';
import CalendarView from '../../free/CalendarView.vue';
import AgendaView from '../../free/AgendaView.vue';
import MonthView from '../../free/MonthView.vue';
import { BLOCK_ALPHA, hexToRgba, hexToRgbTriplet } from '../../free/color-utils';
import { resolveSwatchHex } from '../../free/color-palette';
import { useResolvedTheme } from '../../composables/useTheme';
import type { AvailabilityResponse } from '../../free/nuxt-blocks';

const props = defineProps<{
  token: string;
  keyProtection: 'fragment' | 'passphrase';
  inviteCode: string;
  ownerName: string;
  pageTitle: string;
  locale: string;
  /** 0=Sunday..6=Saturday, owner-configurable (Settings), default Monday. */
  weekStart: number;
  colors: {
    accent: string | null;
    secondary: string | null;
    free: string | null;
    busy: string | null;
    sleep: string | null;
    highlighted: string | null;
    /** Deliberately theme-independent (see dark-theme.css) — a raw hex, not a palette key. */
    now: string | null;
  };
}>();

const resolvedTheme = useResolvedTheme();

const rootStyle = computed(() => {
  const theme = resolvedTheme.value;
  const accent = resolveSwatchHex(props.colors.accent, 'accent', theme);
  const secondary = resolveSwatchHex(props.colors.secondary, 'secondary', theme);
  const free = resolveSwatchHex(props.colors.free, 'free', theme);
  const busy = resolveSwatchHex(props.colors.busy, 'busy', theme);
  const sleep = resolveSwatchHex(props.colors.sleep, 'sleep', theme);
  const highlighted = resolveSwatchHex(props.colors.highlighted, 'highlighted', theme);
  const alpha = BLOCK_ALPHA[theme];

  return {
    '--wtf-accent': accent,
    '--wtf-accent-rgb': hexToRgbTriplet(accent),
    '--wtf-text-muted': secondary,
    '--wtf-color-free': hexToRgba(free, alpha.free),
    '--wtf-hue-free': free,
    '--wtf-color-busy': hexToRgba(busy, alpha.busy),
    '--wtf-color-sleep': hexToRgba(sleep, alpha.sleep),
    '--wtf-hue-sleep': sleep,
    '--wtf-color-highlighted': hexToRgba(highlighted, alpha.highlighted),
    '--wtf-hue-highlighted': highlighted,
    ...(props.colors.now ? { '--wtf-color-now': props.colors.now } : {}),
  };
});

class LinkExpiredError extends Error {}

interface ApiResponse {
  status: 'pending' | 'ready';
  ciphertext?: string;
  key_protection: 'fragment' | 'passphrase';
  wrapped_key?: string | null;
  wrap_salt?: string | null;
  computed_range_start?: string;
  computed_range_end?: string;
  stale?: boolean;
  timezone: string;
}

const showError = ref(false);

// ── Template refs ────────────────────────────────────────────────────
const passphraseInput = ref<HTMLInputElement | null>(null);

// ── Reactive UI state ───────────────────────────────────────────────
const showExpired = ref(false);
const showStatus = ref(true);
const statusText = ref('Decrypting…');
const showCalendar = ref(false);
const timezoneOffsetNote = ref('');
const timezone = ref('UTC');
const showPassphraseModal = ref(false);
const passphraseValue = ref('');
const passphraseErrorText = ref('');
const showPassphraseError = ref(false);

const availability = ref<AvailabilityResponse>({ free: [], highlighted: [], unavailable: [], sleep: [] });

function parseAtParam(): Date | null {
  const at = new URLSearchParams(location.search).get('at');
  if (!at || !/^\d{4}-\d{2}-\d{2}$/.test(at)) return null;
  const [y, m, d] = at.split('-').map(Number);
  return new Date(y, m - 1, d);
}

function parseViewParam(): 'week' | 'month' {
  return new URLSearchParams(location.search).get('view') === 'month' ? 'month' : 'week';
}

const anchorDate = ref(parseAtParam() ?? new Date());
const viewMode = ref<'week' | 'month'>(parseViewParam());

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

const navLabel = computed(() => {
  if (viewMode.value === 'month') {
    return anchorDate.value.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
  }
  const start = weekDays.value[0]!;
  const end = weekDays.value[6]!;
  return `${start.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })} – ${end.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })}`;
});

const hasAnyFreeTime = computed(() => availability.value.free.length > 0);

const now = ref(new Date());
const currentTimePct = computed(() => ((now.value.getHours() * 60 + now.value.getMinutes()) / 1440) * 100);

function goPrev(): void {
  anchorDate.value = viewMode.value === 'week' ? addDaysFns(anchorDate.value, -7) : addMonths(anchorDate.value, -1);
  updateUrl();
}
function goNext(): void {
  anchorDate.value = viewMode.value === 'week' ? addDaysFns(anchorDate.value, 7) : addMonths(anchorDate.value, 1);
  updateUrl();
}
function goToday(): void {
  anchorDate.value = new Date();
  updateUrl();
}
function setViewWeek(): void {
  viewMode.value = 'week';
  updateUrl();
}
function setViewMonth(): void {
  viewMode.value = 'month';
  updateUrl();
}
function onWeekClick(day: Date): void {
  anchorDate.value = day;
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
    timezoneOffsetNote.value = 'Our timezones match!';
    return;
  }

  const abs = Math.abs(diffMinutes);
  const hours = Math.floor(abs / 60);
  const minutes = abs % 60;
  const offsetText = `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}`;
  timezoneOffsetNote.value = diffMinutes > 0
    ? `You are ${offsetText} ahead compared to them`
    : `You are ${offsetText} behind compared to them`;
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

// ── Scrambled placeholder (Stage 6's core visible trust signal) ────────

const SCRAMBLE_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=';

function randomScrambledText(length: number): string {
  let out = '';
  for (let i = 0; i < length; i++) {
    out += SCRAMBLE_CHARS[Math.floor(Math.random() * SCRAMBLE_CHARS.length)];
  }
  return out;
}

const scrambledLines = ref<string[]>([]);
const showScrambled = ref(false);

function renderScrambledPlaceholder(): void {
  showCalendar.value = true;
  showStatus.value = false;
  showScrambled.value = true;
  scrambledLines.value = Array.from({ length: 7 }, () => randomScrambledText(40 + Math.floor(Math.random() * 30)));
}

// ── Decryption ───────────────────────────────────────────────────────

async function resolveContentKey(response: ApiResponse): Promise<CryptoKey> {
  const fragment = location.hash.startsWith('#') ? location.hash.slice(1) : '';

  if (fragment.startsWith('k=')) {
    return importKeyFromFragment(fragment);
  }

  if (props.keyProtection === 'passphrase') {
    return promptForPassphraseKey(response);
  }

  // No fragment, not passphrase-protected: a legacy migrated link (§0.5) —
  // the key derives deterministically from the token itself.
  return deriveLegacyShareLinkKey(props.token);
}

function promptForPassphraseKey(response: ApiResponse): Promise<CryptoKey> {
  return new Promise((resolve) => {
    showPassphraseModal.value = true;
    setTimeout(() => passphraseInput.value?.focus(), 0);

    submitPassphrase = async () => {
      showPassphraseError.value = false;

      try {
        const key = await unwrapKeyWithPassphrase(
          { wrappedKey: response.wrapped_key!, salt: response.wrap_salt! },
          passphraseValue.value,
        );
        showPassphraseModal.value = false;
        resolve(key);
      } catch (error) {
        passphraseErrorText.value = error instanceof DecryptionFailedError
          ? 'Wrong passphrase. Please try again.'
          : 'Something went wrong. Please try again.';
        showPassphraseError.value = true;
      }
    };
  });
}

let submitPassphrase: (() => Promise<void>) | null = null;

function onPassphraseSubmit(): void {
  submitPassphrase?.();
}

async function fetchWithPolling(): Promise<ApiResponse> {
  for (;;) {
    const res = await fetch(`/api/share/${encodeURIComponent(props.token)}`, {
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

    statusText.value = "Your friend's calendar is being fetched for the first time — this can take a moment…";
    await new Promise((r) => setTimeout(r, 2000));
  }
}

// ── Bootstrap ────────────────────────────────────────────────────────

const MINIMUM_SCRAMBLE_DISPLAY_MS = 500;

async function boot(): Promise<void> {
  renderScrambledPlaceholder();
  const scrambleShownAt = Date.now();

  try {
    const response = await fetchWithPolling();
    timezone.value = response.timezone;
    renderTimezoneOffsetNote(response.timezone);

    const key = await resolveContentKey(response);
    const plaintext = await decryptString(key, response.ciphertext!);
    availability.value = JSON.parse(plaintext) as AvailabilityResponse;

    const elapsed = Date.now() - scrambleShownAt;
    if (elapsed < MINIMUM_SCRAMBLE_DISPLAY_MS) {
      await new Promise((r) => setTimeout(r, MINIMUM_SCRAMBLE_DISPLAY_MS - elapsed));
    }

    showScrambled.value = false;
    showStatus.value = false;
  } catch (error) {
    if (error instanceof LinkExpiredError) {
      showExpired.value = true;
      return;
    }

    showScrambled.value = false;
    showCalendar.value = false;
    showStatus.value = true;
    showError.value = true;
    statusText.value = error instanceof DecryptionFailedError
      ? 'Could not decrypt this calendar. The link may be broken.'
      : 'Could not load this calendar right now. Please try again later.';
  }
}

onMounted(() => {
  // /free vs /hu/free decides the whole page's language, not just pageTitle
  // — block labels, durations, date-fns weekday/month names all react to
  // this too (CalendarView.vue/AgendaView.vue/MonthView.vue already key off
  // laravel-vue-i18n's currentLocale). Fire-and-forget: app.ts's i18nVue
  // setup already loaded 'en' at boot, so there's nothing to wait on unless
  // this visitor is on the hu path.
  if (props.locale !== 'en') {
    loadLanguageAsync(props.locale).catch((e) => console.error(e));
  }

  boot();
  const timer = setInterval(() => { now.value = new Date(); }, 30_000);
  onUnmounted(() => clearInterval(timer));
});
</script>

<template>
  <Head :title="pageTitle" />

  <div class="container-fluid py-4" :style="rootStyle">
    <ThemeToggle style="position: absolute; top: 1rem; right: 1rem;" />

    <h1 class="mb-1 text-center">{{ pageTitle }}</h1>
    <p class="small text-center text-muted mt-n2 mb-1">Times shown in your local time</p>
    <p v-if="timezoneOffsetNote" class="small text-center text-muted mt-n1 mb-3">{{ timezoneOffsetNote }}</p>
    <p class="small text-center text-warning mb-3">
      This link is personalized to you. Please don't share it with others.
    </p>

    <div v-if="showExpired" class="text-center py-5">
      <h2 class="h4 mb-3">Link Expired</h2>
      <p class="mb-0 text-muted">This calendar link has expired or is no longer valid.</p>
    </div>

    <div v-else>
      <div class="d-flex flex-wrap align-items-center justify-content-center mb-3" style="gap: 0.5rem;">
        <BButton variant="outline-secondary" size="sm" aria-label="Previous" @click="goPrev">&laquo;</BButton>
        <span class="fw-bold text-center" style="min-width: 12rem;">{{ navLabel }}</span>
        <BButton variant="outline-secondary" size="sm" aria-label="Next" @click="goNext">&raquo;</BButton>
        <BButton variant="secondary" size="sm" class="ms-2" @click="goToday">Today</BButton>
        <div class="btn-group ms-2" role="group">
          <BButton
            size="sm"
            :variant="viewMode === 'month' ? 'secondary' : 'outline-secondary'"
            @click="setViewMonth"
          >
            Month
          </BButton>
          <BButton
            size="sm"
            :variant="viewMode === 'week' ? 'secondary' : 'outline-secondary'"
            @click="setViewWeek"
          >
            Week
          </BButton>
        </div>
      </div>

      <div v-if="showScrambled" class="border rounded p-3" style="filter: blur(0.5px);" aria-hidden="true">
        <div v-for="(line, i) in scrambledLines" :key="i" class="mb-2 font-monospace small text-muted" style="opacity: 0.5;">
          {{ line }}
        </div>
      </div>

      <div v-else-if="showStatus" class="text-center text-muted py-5">
        <span>{{ statusText }}</span>
      </div>

      <template v-else-if="showCalendar">
        <div class="wtf-desktop-only">
          <CalendarView
            v-if="viewMode === 'week'"
            :visible-days="visibleDays"
            :free-slots="availability.free"
            :highlighted-slots="availability.highlighted"
            :unavailable-slots="availability.unavailable"
            :sleep-slots="availability.sleep"
            :pending="false"
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
            :sleep-slots="availability.sleep"
            :pending="false"
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
          :sleep-slots="availability.sleep"
          :pending="false"
          :has-error="showError"
          :timezone="timezone"
          :show-blocks="true"
          :show-current-time="true"
          :current-time-pct="currentTimePct"
        />
      </template>
    </div>
  </div>

  <div
    v-if="showPassphraseModal"
    class="position-fixed d-flex align-items-center justify-content-center"
    style="inset: 0; background: rgba(0,0,0,0.6); z-index: 1000;"
  >
    <div class="card p-4" style="max-width: 24rem; width: 90%;">
      <h2 class="h5 mb-3">Enter passphrase</h2>
      <p class="small text-muted">This calendar requires a passphrase to view.</p>
      <form @submit.prevent="onPassphraseSubmit">
        <BFormInput
          ref="passphraseInput"
          v-model="passphraseValue"
          type="password"
          class="mb-3"
          autocomplete="off"
          required
        />
        <p v-show="showPassphraseError" class="small text-danger mb-3">{{ passphraseErrorText }}</p>
        <BButton type="submit" variant="secondary" class="w-100">Unlock</BButton>
      </form>
    </div>
  </div>

  <p class="text-center small text-muted mt-4">
    <a :href="`/register?code=${inviteCode}`">
      Want your own {{ $page.props.appName }} calendar? You're invited by {{ ownerName }} to create one.
    </a>
  </p>
</template>
