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
 */
import axios from 'axios';
import { BProgress, BProgressBar } from 'bootstrap-vue-next';
import { onMounted, ref, watch } from 'vue';
import { decryptString } from '../crypto';
import { useVault } from './useVault';
import { requestUnlock } from './vaultModal';

interface AvailRow {
  title: string;
  notAvail: boolean;
  sleepLabel: string; sleepPct: number; sleepBarPct: number;
  workLabel: string | null; workPct: number; workBarPct: number;
  busyLabel: string | null; busyPct: number; busyBarPct: number;
  freeLabel: string | null; freePct: number | null;
}

interface HighlightEntry {
  share_link_id: string;
  minutes: number;
  connection: { id: string; name_ciphertext: string } | null;
  share_link_label_ciphertext: string | null;
}

interface StatsResponse {
  error?: string;
  rows?: AvailRow[];
  highlights?: HighlightEntry[];
  highlightsRest?: HighlightEntry[];
}

const { getRecordKey, vaultUnlocked } = useVault();

const loading = ref(true);
const errorCode = ref<string | null>(null);
const rows = ref<AvailRow[]>([]);
const highlights = ref<HighlightEntry[]>([]);
const highlightsRest = ref<HighlightEntry[]>([]);
const labels = ref<Record<string, string>>({});

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
  for (const entry of [...highlights.value, ...highlightsRest.value]) {
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
          <span v-if="row.sleepPct > 0">{{ index > 0 ? `${row.sleepPct}%` : row.sleepLabel }} sleep</span>
          <span v-if="row.workPct > 0" class="text-primary ms-2">{{ index > 0 ? `${row.workPct}%` : row.workLabel }} work</span>
          <span v-if="row.busyPct > 0" class="text-danger ms-2">{{ index > 0 ? `${row.busyPct}%` : row.busyLabel }} busy</span>
          <span v-if="row.freePct !== null" class="text-secondary ms-2">{{ index > 0 ? `${row.freePct}%` : row.freeLabel }} free</span>
        </span>
      </div>
      <BProgress style="height:8px">
        <BProgressBar v-if="row.sleepBarPct > 0" :value="row.sleepBarPct" variant="secondary" />
        <BProgressBar v-if="row.workBarPct > 0" :value="row.workBarPct" variant="primary" />
        <BProgressBar v-if="row.busyBarPct > 0" :value="row.busyBarPct" variant="danger" />
      </BProgress>
    </div>

    <template v-if="highlights.length > 0">
      <div class="small fw-semibold mb-1 mt-3">Top highlights (past 30 days)</div>
      <p v-if="!vaultUnlocked" class="small mb-0">
        <a href="#" @click.prevent="requestUnlock()">Unlock your vault</a> to see names.
      </p>
      <ol v-else class="list-unstyled mb-0 small">
        <li v-for="entry in highlights" :key="entry.share_link_id" class="d-flex justify-content-between">
          <span>{{ labelFor(entry) }}</span>
          <span class="ms-2 flex-shrink-0">{{ fmtMin(entry.minutes) }}</span>
        </li>
        <li v-if="highlightsRest.length > 0" class="d-flex justify-content-between text-muted">
          <span>{{ highlightsRest.map((entry) => labelFor(entry)).join(', ') }}</span>
          <span class="ms-2 flex-shrink-0">&le;&nbsp;{{ fmtMin(highlightsRest[0].minutes) }}</span>
        </li>
      </ol>
    </template>
  </template>
</template>
