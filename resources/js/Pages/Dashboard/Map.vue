<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { BCard } from 'bootstrap-vue-next';
import { computed } from 'vue';
import PageHeader from '../../Components/PageHeader.vue';
import type { LinkedProfiles } from '../../dashboard/connectionIdentity';
import type { DiscordSummary } from '../../dashboard/discord';
import ConnectionsMap from '../../dashboard/graph/ConnectionsMap.vue';
import type { TelegramSummary } from '../../dashboard/telegram';
import type { VrchatSummary } from '../../dashboard/vrchat';
import DashboardLayout from '../../Layouts/DashboardLayout.vue';

defineOptions({ layout: DashboardLayout });

const props = defineProps<{
  connections: { id: string; name_ciphertext: string; preferred_identity: string | null }[];
  sources: { id: string; category_id: string | null; name_ciphertext: string; icon_ciphertext: string | null }[];
  categories: { id: string; icon_ciphertext: string | null }[];
  vrchat: VrchatSummary;
  discord: DiscordSummary;
  telegram: TelegramSummary;
}>();

const profiles = computed<Record<string, LinkedProfiles>>(() =>
  Object.fromEntries(props.connections.map((c) => [c.id, {
    discord: props.discord.profiles[c.id],
    vrchat: props.vrchat.profiles[c.id],
    telegram: props.telegram.profiles[c.id],
  }])),
);
</script>

<template>
  <Head title="Map" />

  <PageHeader title="Map" subtitle="Explore how your connections link up. Scroll to zoom, drag to pan, click a connection to focus on it." />

  <BCard body-class="p-2">
    <ConnectionsMap :connections="connections" :sources="sources" :categories="categories" :profiles="profiles" />
  </BCard>
</template>
