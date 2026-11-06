<script setup lang="ts">
/**
 * A connection's linked VRChat accounts: profile picture, name and the time
 * spent together, as cached by the server from the owner's VRCX Headless server
 * (§0.2 tier — not E2EE, unlike the rest of the card). Nothing renders for a
 * connection with no linked account.
 */
import axios from 'axios';
import { BButton } from 'bootstrap-vue-next';
import { computed, ref } from 'vue';
import { formatDuration, type VrchatProfile, type VrchatStay, type VrchatSummary } from './vrchat';

const props = defineProps<{
  connectionId: string;
  profiles: VrchatProfile[];
  syncedAt: string | null;
}>();
const emit = defineEmits<{ synced: [VrchatSummary] }>();

const syncing = ref(false);
const syncError = ref('');
const syncWarnings = ref<string[]>([]);
const stays = ref<VrchatStay[] | null>(null);
const staysOpen = ref(false);
const staysLoading = ref(false);

const names = computed(() => Object.fromEntries(props.profiles.map((p) => [p.vrchat_user_id, p.display_name ?? p.vrchat_user_id])));
const when = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' });

function formatDate(iso: string | null): string {
  return iso ? when.format(new Date(iso)) : '—';
}

async function syncNow(): Promise<void> {
  syncing.value = true;
  syncError.value = '';

  try {
    const { data } = await axios.post<VrchatSummary & { ran: boolean; warnings?: string[] }>('/settings/vrcx/sync');
    syncWarnings.value = data.warnings ?? [];
    emit('synced', data);
    if (staysOpen.value) await loadStays();
  } catch (error) {
    syncError.value = axios.isAxiosError(error) ? (error.response?.data?.message ?? 'Sync failed.') : 'Sync failed.';
  } finally {
    syncing.value = false;
  }
}

async function loadStays(): Promise<void> {
  staysLoading.value = true;

  try {
    const { data } = await axios.get<{ stays: VrchatStay[] }>(`/dashboard/connections/${props.connectionId}/vrchat/stays`);
    stays.value = data.stays;
  } catch (error) {
    console.error(error);
  } finally {
    staysLoading.value = false;
  }
}

async function toggleStays(): Promise<void> {
  staysOpen.value = !staysOpen.value;
  if (staysOpen.value && stays.value === null) await loadStays();
}
</script>

<template>
  <div v-if="profiles.length">
    <div v-for="profile in profiles" :key="profile.vrchat_user_id" class="d-flex align-items-center gap-3 mb-2">
      <img
        v-if="profile.picture_url"
        :src="profile.picture_url"
        :alt="`${profile.display_name ?? 'VRChat user'}'s profile picture`"
        width="48"
        height="48"
        class="rounded flex-shrink-0 object-fit-cover"
        loading="lazy"
        referrerpolicy="no-referrer"
      >
      <div v-else class="rounded bg-body-secondary flex-shrink-0" style="width: 48px; height: 48px;" aria-hidden="true" />
      <div class="small">
        <div class="fw-semibold">{{ profile.display_name ?? 'Not cached by VRCX Headless yet' }}</div>
        <div class="text-muted font-monospace">{{ profile.vrchat_user_id }}</div>
        <div v-if="profile.discord_id" class="text-muted">Discord ID <span class="font-monospace">{{ profile.discord_id }}</span></div>
        <div v-if="profile.stays">
          <strong>{{ formatDuration(profile.total_ms) }}</strong> together over {{ profile.stays }} visit{{ profile.stays === 1 ? '' : 's' }}
          <span class="text-muted">
            ({{ formatDuration(profile.one_on_one_ms) }} one-on-one · last {{ formatDate(profile.last_at) }})
          </span>
        </div>
        <div v-else class="text-muted">No shared instances recorded yet.</div>
      </div>
    </div>

    <div class="small d-flex flex-wrap align-items-center gap-2">
      <BButton size="sm" variant="outline-secondary" @click="toggleStays">{{ staysOpen ? 'Hide visits' : 'Show visits' }}</BButton>
      <BButton size="sm" variant="outline-secondary" :disabled="syncing" @click="syncNow">{{ syncing ? 'Syncing…' : 'Sync now' }}</BButton>
      <span class="text-muted">{{ syncedAt ? `Synced ${formatDate(syncedAt)}` : 'Not synced yet' }} · not end-to-end encrypted</span>
    </div>
    <div v-if="syncError" class="text-danger small mt-1">{{ syncError }}</div>
    <div v-for="warning in syncWarnings" :key="warning" class="text-warning small mt-1">{{ warning }}</div>

    <div v-if="staysOpen" class="mt-2">
      <p v-if="staysLoading" class="small text-muted mb-0">Loading…</p>
      <p v-else-if="!stays?.length" class="small text-muted mb-0">No visits recorded.</p>
      <table v-else class="table table-sm small w-auto mb-0">
        <thead>
          <tr><th>Started</th><th>Length</th><th>Others there</th><th v-if="profiles.length > 1">With</th></tr>
        </thead>
        <tbody>
          <tr v-for="stay in stays" :key="stay.vrchat_user_id + stay.start">
            <td>{{ formatDate(stay.start) }}</td>
            <td>{{ formatDuration(stay.duration_ms) }}</td>
            <td>{{ stay.other_player_count === 0 ? 'one-on-one' : stay.other_player_count }}</td>
            <td v-if="profiles.length > 1">{{ names[stay.vrchat_user_id] }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
