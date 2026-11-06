<script setup lang="ts">
/**
 * A connection's linked Discord accounts: avatar, display name, username (and your
 * own nickname for them, when you set one), as saved by the server from your Discord
 * friend list or an earlier lookup (§0.2 tier — not E2EE, unlike the rest of the card).
 * An ID nobody has looked up yet is a placeholder, like VRChat's "not cached yet", with a
 * Sync button that asks Discord about that ID directly (the server's bot token does it,
 * so it works for people who are not your friends). Nothing renders for a connection
 * with no Discord ID.
 */
import axios from 'axios';
import { BButton } from 'bootstrap-vue-next';
import { computed, ref } from 'vue';
import { type DiscordProfile } from './discord';

const props = defineProps<{
  connectionId: string;
  profiles: DiscordProfile[];
  syncedAt: string | null;
  /** The server can look an ID up (it has a bot token). */
  canLookUp: boolean;
}>();
const emit = defineEmits<{ synced: [DiscordProfile[]] }>();

const when = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' });

/** The name to lead with: their display name, else their username. */
function title(profile: DiscordProfile): string {
  return profile.global_name || profile.username || profile.discord_user_id;
}

const syncing = ref(false);
const syncError = ref('');
const anyUnknown = computed(() => props.profiles.some((p) => !p.known));

async function syncNow(): Promise<void> {
  syncing.value = true;
  syncError.value = '';

  try {
    const { data } = await axios.post<{ profiles: DiscordProfile[] }>(`/dashboard/connections/${props.connectionId}/discord/lookup`);
    emit('synced', data.profiles);
  } catch (error) {
    syncError.value = axios.isAxiosError(error) ? (error.response?.data?.message ?? 'Lookup failed.') : 'Lookup failed.';
  } finally {
    syncing.value = false;
  }
}
</script>

<template>
  <div v-if="profiles.length">
    <div v-for="profile in profiles" :key="profile.discord_user_id" class="d-flex align-items-center gap-3 mb-2">
      <img
        v-if="profile.picture_url"
        :src="profile.picture_url"
        :alt="`${title(profile)}'s Discord avatar`"
        width="48"
        height="48"
        class="rounded flex-shrink-0 object-fit-cover"
        loading="lazy"
        referrerpolicy="no-referrer"
      >
      <div v-else class="rounded bg-body-secondary flex-shrink-0" style="width: 48px; height: 48px;" aria-hidden="true" />
      <div class="small">
        <div v-if="profile.known" class="fw-semibold">{{ title(profile) }}</div>
        <div v-else class="fw-semibold text-muted">Not looked up yet</div>
        <div v-if="profile.username && profile.username !== title(profile)" class="text-muted">@{{ profile.username }}</div>
        <div v-if="profile.nickname" class="text-muted">Your nickname for them: {{ profile.nickname }}</div>
        <div class="text-muted font-monospace">{{ profile.discord_user_id }}</div>
      </div>
    </div>
    <div class="small d-flex flex-wrap align-items-center gap-2">
      <BButton v-if="canLookUp" size="sm" variant="outline-secondary" :disabled="syncing" @click="syncNow">
        {{ syncing ? 'Syncing…' : anyUnknown ? 'Sync' : 'Sync again' }}
      </BButton>
      <span class="text-muted">
        <template v-if="!profiles.some((p) => p.known)">
          {{ canLookUp ? 'Sync asks Discord about this ID' : 'Connect Discord and sync your friends to fill this in' }}
        </template>
        <template v-else>{{ syncedAt ? `Friends synced ${when.format(new Date(syncedAt))}` : 'Looked up' }}</template>
        · not end-to-end encrypted
      </span>
    </div>
    <div v-if="syncError" class="text-danger small mt-1">{{ syncError }}</div>
  </div>
</template>
