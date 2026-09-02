<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { BCard } from 'bootstrap-vue-next';
import AvailabilityStats from '../../dashboard/AvailabilityStats.vue';
import ConnectionsGraph from '../../dashboard/ConnectionsGraph.vue';
import DashboardLayout from '../../Layouts/DashboardLayout.vue';

defineOptions({ layout: DashboardLayout });

defineProps<{
  userName: string;
  shareLinkCount: number;
  connectionCount: number;
  hasCalendarUrl: boolean;
}>();
</script>

<template>
  <Head title="Dashboard" />

  <h1 class="h3 mb-4">Welcome, {{ userName }}</h1>

  <div v-if="!hasCalendarUrl" class="alert alert-info">
    You haven't added a calendar yet. Head to <Link href="/settings">Settings</Link> to
    paste your calendar's ICS URL and preview it.
  </div>

  <div class="row">
    <div class="col-md-4 mb-3">
      <div class="card h-100">
        <div class="card-body">
          <h2 class="h5">Share links</h2>
          <p class="display-4 mb-2">{{ shareLinkCount }}</p>
          <Link href="/dashboard/share-links" class="btn btn-outline-secondary btn-sm">Manage</Link>
        </div>
      </div>
    </div>
    <div class="col-md-4 mb-3">
      <div class="card h-100">
        <div class="card-body">
          <h2 class="h5">Connections</h2>
          <p class="display-4 mb-2">{{ connectionCount }}</p>
          <Link href="/dashboard/connections" class="btn btn-outline-secondary btn-sm">Manage</Link>
        </div>
      </div>
    </div>
    <div class="col-md-4 mb-3">
      <div class="card h-100">
        <div class="card-body">
          <h2 class="h5">Settings</h2>
          <p class="small text-muted mb-2">Calendar, sleep windows, page colors, and more.</p>
          <Link href="/settings" class="btn btn-outline-secondary btn-sm">Open</Link>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-4">
    <div class="col-md-6">
      <BCard v-if="hasCalendarUrl" class="h-100">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h2 class="h5 mb-0">Availability</h2>
          <Link href="/settings" class="btn btn-sm btn-outline-secondary">Manage</Link>
        </div>
        <AvailabilityStats />
      </BCard>
    </div>

    <div class="col-md-6">
      <BCard class="h-100">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h2 class="h5 mb-0">Connections</h2>
          <Link href="/dashboard/connections" class="btn btn-sm btn-outline-secondary">Manage</Link>
        </div>
        <p v-if="connectionCount === 0" class="text-muted mb-0">
          No connections tracked yet. Add some on the
          <Link href="/dashboard/connections">Connections page</Link> to see your network graph
          here.
        </p>
        <ConnectionsGraph v-else />
      </BCard>
    </div>
  </div>
</template>
