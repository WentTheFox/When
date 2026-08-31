<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
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
</template>
