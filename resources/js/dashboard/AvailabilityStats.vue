<script setup lang="ts">
/**
 * Time-breakdown (sleep/work/busy/free for Today, This week, Past 30 days)
 * + top-time-spent leaderboard — a close port of the source app's own
 * dashboard widgets (its own dashboard.ts's avail-stats/highlight-list
 * handling), fed by DashboardController::statsAvailability(). Both
 * widgets share one fetch since the backend computes them from a single
 * ICS parse.
 *
 * The leaderboard's per-row label is never sent by the server (§0.1 — see
 * the controller's own doc comment): each entry decrypts a linked
 * connection's name, or the share link's own label, client-side once the
 * vault is unlocked, the same way ConnectionCard.vue/ShareLinkCard.vue
 * already do.
 *
 * Clicking a leaderboard row (or a "no time yet" name) opens the source
 * app's "highlight events" dialog, ported here as a BModal listing every
 * matched slot for that share link. Unlike the source app — a plain
 * substring match against raw ICS event titles, so its dialog can bold the
 * matched word inside the real title — this app's highlight matching is
 * clause-based (HighlightMatcher: "with X"/"Host X"/"Visit X"), so there's
 * no single raw event name per match. The dialog shows what actually did
 * match instead: each slot's time range, its extracted activity (if any),
 * Visiting/Hosting/Tentative labels, and the matched word(s) — the same
 * fields the /free viewer itself renders for a highlighted block.
 *
 * The sleep/busy/work/free rows use the owner's own theme colors (same
 * ColorPalette slots the /free viewer itself resolves via
 * resolveSwatchHex), not Bootstrap's stock text-primary/text-danger/
 * bg-primary/bg-danger utility classes — those don't track an owner's
 * chosen palette (dark-theme.css repaints .text-primary to --wtf-accent
 * app-wide but leaves .bg-primary at Bootstrap's own stock blue, so the
 * label text and its own bar segment didn't even agree with each other).
 */
import axios from 'axios';
import { BModal, BProgress, BProgressBar } from 'bootstrap-vue-next';
import { computed, onMounted, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useResolvedTheme } from '../composables/useTheme';
import { resolveSwatchHex } from '../free/color-palette';
import type { SharedPageProps } from '../sharedPageProps';
import { decryptString } from '../crypto';
import { useVault } from './useVault';
import { requestUnlock } from './vaultModal';

const page = usePage<SharedPageProps>();
const resolvedTheme = useResolvedTheme();

const sleepColor = computed(() => resolveSwatchHex(page.props.auth.user?.sleepColorKey, 'sleep', resolvedTheme.value));
const busyColor = computed(() => resolveSwatchHex(page.props.auth.user?.busyColorKey, 'busy', resolvedTheme.value));
const workColor = computed(() => resolveSwatchHex(page.props.auth.user?.workColorKey, 'work', resolvedTheme.value));
const freeColor = computed(() => resolveSwatchHex(page.props.auth.user?.freeColorKey, 'free', resolvedTheme.value));

interface AvailRow {
  title: string;
  notAvail: boolean;
  sleepLabel: string; sleepPct: number; sleepBarPct: number;
  workLabel: string | null; workPct: number; workBarPct: number;
  busyLabel: string | null; busyPct: number; busyBarPct: number;
  freeLabel: string | null; freePct: number | null;
}

interface HighlightSlot {
  start: string;
  end: string;
  tentative_start: boolean;
  tentative_end: boolean;
  activity: string | null;
  visiting: boolean;
  hosting: boolean;
  highlight_words: string[];
}

interface HighlightEntry {
  share_link_id: string;
  minutes: number;
  connection: { id: string; name_ciphertext: string } | null;
  share_link_label_ciphertext: string | null;
  events: HighlightSlot[];
}

interface StatsResponse {
  error?: string;
  rows?: AvailRow[];
  highlights?: HighlightEntry[];
  highlightsRest?: HighlightEntry[];
  highlightsNoTime?: HighlightEntry[];
}

const { getRecordKey, vaultUnlocked } = useVault();

const loading = ref(true);
const errorCode = ref<string | null>(null);
const rows = ref<AvailRow[]>([]);
const highlights = ref<HighlightEntry[]>([]);
const highlightsRest = ref<HighlightEntry[]>([]);
const highlightsNoTime = ref<HighlightEntry[]>([]);
const labels = ref<Record<string, string>>({});

const eventsModalOpen = ref(false);
const eventsModalEntry = ref<HighlightEntry | null>(null);

function openEventsModal(entry: HighlightEntry): void {
  eventsModalEntry.value = entry;
  eventsModalOpen.value = true;
}

function fmtSlotTime(iso: string): string {
  return new Date(iso).toLocaleString(undefined, {
    month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit',
  });
}

function fmtMin(m: number): string {
  if (m >= 1440) {
    const d = Math.floor(m / 1440);
    const r = m % 1440;
    return `${d}d ${Math.floor(r / 60)}:${String(r % 60).padStart(2, '0')}`;
  }
  return `${Math.floor(m / 60)}:${String(m % 60).padStart(2, '0')}`;
}

async function decryptLabel(entry: HighlightEntry): Promise<string> {
  try {
    if (entry.connection) {
      const key = await getRecordKey(entry.connection.id);
      return await decryptString(key, entry.connection.name_ciphertext);
    }
    if (entry.share_link_label_ciphertext) {
      const key = await getRecordKey(entry.share_link_id);
      return await decryptString(key, entry.share_link_label_ciphertext);
    }
  } catch (error) {
    console.error(error);
  }
  return 'Unnamed';
}

async function decryptLabels(): Promise<void> {
  for (const entry of [...highlights.value, ...highlightsRest.value, ...highlightsNoTime.value]) {
    const cacheKey = entry.connection?.id ?? entry.share_link_id;
    if (labels.value[cacheKey]) continue;
    labels.value[cacheKey] = await decryptLabel(entry);
  }
}

function labelFor(entry: HighlightEntry): string {
  return labels.value[entry.connection?.id ?? entry.share_link_id] ?? '…';
}

watch(vaultUnlocked, (unlocked) => {
  if (unlocked) decryptLabels();
}, { immediate: true });

onMounted(async () => {
  try {
    const { data } = await axios.get<StatsResponse>('/dashboard/stats/availability');
    if (data.error || !data.rows) {
      errorCode.value = data.error ?? 'fetch_failed';
    } else {
      rows.value = data.rows;
      highlights.value = data.highlights ?? [];
      highlightsRest.value = data.highlightsRest ?? [];
      highlightsNoTime.value = data.highlightsNoTime ?? [];
      if (vaultUnlocked.value) await decryptLabels();
    }
  } catch (error) {
    console.error(error);
    errorCode.value = 'fetch_failed';
  } finally {
    loading.value = false;
  }
});
</script>

<template>
  <div v-if="loading">
    <div v-for="title in ['Today', 'This week', 'Past 30 days']" :key="title" class="mb-3">
      <div class="d-flex justify-content-between small mb-1">
        <span class="fw-semibold">{{ title }}</span>
        <span class="placeholder-glow"><span class="placeholder" style="width:8rem"></span></span>
      </div>
      <div class="progress" style="height:8px">
        <div class="progress-bar bg-secondary progress-bar-striped progress-bar-animated" style="width:100%"></div>
      </div>
    </div>
  </div>

  <p v-else-if="errorCode === 'no_calendar'" class="text-muted mb-0">
    No calendar URL configured. Add one on the <a href="/settings">Settings page</a> to see your
    free/busy stats here.
  </p>
  <p v-else-if="errorCode" class="text-danger mb-0">Failed to fetch calendar data.</p>

  <template v-else>
    <div v-for="(row, index) in rows" :key="row.title" class="mb-3">
      <div class="d-flex justify-content-between small mb-1">
        <span class="fw-semibold">{{ row.title }}</span>
        <span v-if="row.notAvail" class="text-muted">Not available</span>
        <span v-else>
          <span v-if="row.sleepPct > 0" :style="{ color: sleepColor }">{{ index > 0 ? `${row.sleepPct}%` : row.sleepLabel }} sleep</span>
          <span v-if="row.workPct > 0" class="ms-2" :style="{ color: workColor }">{{ index > 0 ? `${row.workPct}%` : row.workLabel }} work</span>
          <span v-if="row.busyPct > 0" class="ms-2" :style="{ color: busyColor }">{{ index > 0 ? `${row.busyPct}%` : row.busyLabel }} busy</span>
          <span v-if="row.freePct !== null" class="ms-2" :style="{ color: freeColor }">{{ index > 0 ? `${row.freePct}%` : row.freeLabel }} free</span>
        </span>
      </div>
      <BProgress style="height:8px">
        <BProgressBar v-if="row.sleepBarPct > 0" :value="row.sleepBarPct" :style="{ backgroundColor: sleepColor }" />
        <BProgressBar v-if="row.workBarPct > 0" :value="row.workBarPct" :style="{ backgroundColor: workColor }" />
        <BProgressBar v-if="row.busyBarPct > 0" :value="row.busyBarPct" :style="{ backgroundColor: busyColor }" />
      </BProgress>
    </div>

    <template v-if="highlights.length > 0">
      <div class="small fw-semibold mb-1 mt-3">Top highlights (past 30 days)</div>
      <p v-if="!vaultUnlocked" class="small mb-0">
        <a href="#" @click.prevent="requestUnlock()">Unlock your vault</a> to see names.
      </p>
      <ol v-else class="list-unstyled mb-0 small">
        <li v-for="entry in highlights" :key="entry.share_link_id" class="d-flex justify-content-between">
          <button type="button" class="btn btn-link p-0 align-baseline text-start text-decoration-none" @click="openEventsModal(entry)">
            {{ labelFor(entry) }}
          </button>
          <span class="ms-2 flex-shrink-0">{{ fmtMin(entry.minutes) }}</span>
        </li>
        <li v-if="highlightsRest.length > 0" class="d-flex justify-content-between text-muted">
          <span>
            <template v-for="(entry, i) in highlightsRest" :key="entry.share_link_id">
              <button type="button" class="btn btn-link p-0 align-baseline text-muted text-decoration-none" @click="openEventsModal(entry)">{{ labelFor(entry) }}</button>{{ i < highlightsRest.length - 1 ? ', ' : '' }}
            </template>
          </span>
          <span class="ms-2 flex-shrink-0">&le;&nbsp;{{ fmtMin(highlightsRest[0].minutes) }}</span>
        </li>
      </ol>
    </template>

    <template v-if="highlightsNoTime.length > 0">
      <div class="small fw-semibold mb-1 mt-3">No time yet (past 30 days)</div>
      <p v-if="!vaultUnlocked" class="small mb-0">
        <a href="#" @click.prevent="requestUnlock()">Unlock your vault</a> to see names.
      </p>
      <p v-else class="small text-muted mb-0">
        <template v-for="(entry, i) in highlightsNoTime" :key="entry.share_link_id">
          <button type="button" class="btn btn-link p-0 align-baseline text-muted text-decoration-none" @click="openEventsModal(entry)">{{ labelFor(entry) }}</button>{{ i < highlightsNoTime.length - 1 ? ', ' : '' }}
        </template>
      </p>
    </template>
  </template>

  <BModal v-model="eventsModalOpen" :title="eventsModalEntry ? labelFor(eventsModalEntry) : ''" no-footer size="lg">
    <div v-if="!eventsModalEntry || eventsModalEntry.events.length === 0" class="text-muted small p-3 text-center">
      No matched events.
    </div>
    <div v-else class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <thead>
          <tr>
            <th>When</th>
            <th>Details</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(slot, i) in eventsModalEntry.events" :key="i">
            <td class="text-nowrap small">
              {{ fmtSlotTime(slot.start) }}{{ slot.tentative_start ? '?' : '' }}
              &ndash;
              {{ fmtSlotTime(slot.end) }}{{ slot.tentative_end ? '?' : '' }}
            </td>
            <td class="small">
              {{ slot.activity ?? slot.highlight_words.join(', ') }}
              <span v-if="slot.visiting" class="badge bg-info text-dark ms-1">Visiting</span>
              <span v-if="slot.hosting" class="badge bg-info text-dark ms-1">Hosting</span>
              <span v-if="slot.tentative_start || slot.tentative_end" class="badge bg-secondary ms-1">Tentative</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </BModal>
</template>
