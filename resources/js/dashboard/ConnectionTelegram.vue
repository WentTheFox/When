<script setup lang="ts">
/**
 * A connection's linked Telegram contacts: photo, name and username, as saved by the server from your
 * Telegram contact list (§0.2 tier — not E2EE, unlike the rest of the card). An ID the sync has not
 * seen is a placeholder. Nothing renders for a connection with no Telegram ID.
 */
import axios from 'axios';
import { BButton } from 'bootstrap-vue-next';
import { ref } from 'vue';
import { type TelegramProfile, type TelegramSummary } from './telegram';

defineProps<{
  profiles: TelegramProfile[];
  syncedAt: string | null;
  /** The owner is logged in to Telegram with an ID field chosen, so a sync can run. */
  canSync?: boolean;
}>();
/** Telegram only lists the whole address book, so a sync here refreshes every contact: the new summary is handed up. */
const emit = defineEmits<{ synced: [TelegramSummary] }>();

const syncing = ref(false);
const syncError = ref('');

async function syncNow(): Promise<void> {
  syncing.value = true;
  syncError.value = '';

  try {
    const { data } = await axios.post<TelegramSummary>('/settings/telegram/sync');
    emit('synced', data);
  } catch (error) {
    syncError.value = axios.isAxiosError(error) ? (error.response?.data?.message ?? 'Sync failed.') : 'Sync failed.';
  } finally {
    syncing.value = false;
  }
}

const when = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' });

/** The name to lead with: their name, else their username. */
function title(profile: TelegramProfile): string {
  return profile.name || (profile.username ? `@${profile.username}` : profile.telegram_user_id);
}
</script>

<template>
  <div v-if="profiles.length">
    <div v-for="profile in profiles" :key="profile.telegram_user_id" class="d-flex align-items-center gap-3 mb-2">
      <img
        v-if="profile.picture_url"
        :src="profile.picture_url"
        :alt="`${title(profile)}'s Telegram photo`"
        width="48"
        height="48"
        class="rounded flex-shrink-0 object-fit-cover"
      >
      <div v-else class="rounded bg-body-secondary flex-shrink-0" style="width: 48px; height: 48px;" aria-hidden="true" />
      <div class="small">
        <div v-if="profile.known" class="fw-semibold">{{ title(profile) }}</div>
        <div v-else class="fw-semibold text-muted">Not in your synced contacts</div>
        <div v-if="profile.username && profile.name" class="text-muted">@{{ profile.username }}</div>
        <div class="text-muted font-monospace">{{ profile.telegram_user_id }}</div>
      </div>
    </div>
    <div class="small d-flex flex-wrap align-items-center gap-2">
      <BButton v-if="canSync" size="sm" variant="outline-secondary" :disabled="syncing" @click="syncNow">
        {{ syncing ? 'Syncing…' : 'Sync all Telegram contacts' }}
      </BButton>
      <span class="text-muted">
        <template v-if="profiles.some((p) => p.known)">{{ syncedAt ? `Contacts synced ${when.format(new Date(syncedAt))}` : 'Synced' }}</template>
        <template v-else>{{ canSync ? 'Reads your whole Telegram contact list, not just this person' : 'Log in to Telegram in Settings to fill this in' }}</template>
        · not end-to-end encrypted
      </span>
    </div>
    <div v-if="syncError" class="text-danger small mt-1">{{ syncError }}</div>
  </div>
</template>
