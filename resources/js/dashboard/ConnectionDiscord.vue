<script setup lang="ts">
/**
 * A connection's linked Discord accounts: avatar, display name, username (and your
 * own nickname for them, when you set one), as saved by the server from your Discord
 * friend list (§0.2 tier — not E2EE, unlike the rest of the card). Nothing renders
 * for a connection with no linked friend.
 */
import { type DiscordProfile } from './discord';

defineProps<{
  profiles: DiscordProfile[];
  syncedAt: string | null;
}>();

const when = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' });

/** The name to lead with: their display name, else their username. */
function title(profile: DiscordProfile): string {
  return profile.global_name || profile.username || profile.discord_user_id;
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
        <div class="fw-semibold">{{ title(profile) }}</div>
        <div v-if="profile.username && profile.username !== title(profile)" class="text-muted">@{{ profile.username }}</div>
        <div v-if="profile.nickname" class="text-muted">Your nickname for them: {{ profile.nickname }}</div>
        <div class="text-muted font-monospace">{{ profile.discord_user_id }}</div>
      </div>
    </div>
    <div class="small text-muted">
      {{ syncedAt ? `Synced ${when.format(new Date(syncedAt))}` : 'Not synced yet' }} · not end-to-end encrypted
    </div>
  </div>
</template>
