<script setup lang="ts">
/**
 * A connection's linked Telegram contacts: photo, name and username, as saved by the server from your
 * Telegram contact list (§0.2 tier — not E2EE, unlike the rest of the card). An ID the sync has not
 * seen is a placeholder. Nothing renders for a connection with no Telegram ID.
 */
import { type TelegramProfile } from './telegram';

defineProps<{
  profiles: TelegramProfile[];
  syncedAt: string | null;
}>();

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
    <div class="small text-muted">
      <template v-if="profiles.some((p) => p.known)">{{ syncedAt ? `Contacts synced ${when.format(new Date(syncedAt))}` : 'Synced' }}</template>
      <template v-else>Sync your Telegram contacts in Settings to fill this in</template>
      · not end-to-end encrypted
    </div>
  </div>
</template>
