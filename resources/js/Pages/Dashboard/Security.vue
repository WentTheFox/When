<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { BBadge, BButton, BCard } from 'bootstrap-vue-next';
import DashboardLayout from '../../Layouts/DashboardLayout.vue';

defineOptions({ layout: DashboardLayout });

defineProps<{
  twoFactorEnabled: boolean;
}>();

const disableTwoFactorForm = useForm({});

function disableTwoFactor(): void {
  if (!confirm('Turn off two-factor authentication for your account?')) return;
  disableTwoFactorForm.delete('/two-factor', { preserveScroll: true });
}
</script>

<template>
  <h1 class="h3 mb-4">Security</h1>

  <BCard class="mb-4">
    <h2 class="h5 mb-3">
      Two-factor authentication
      <BBadge v-if="twoFactorEnabled" variant="success" class="ms-1">Enabled</BBadge>
      <BBadge v-else variant="secondary" class="ms-1">Not enabled</BBadge>
    </h2>
    <p class="small text-muted mb-3">
      Adds a one-time code from an authenticator app to every login, separate from your
      master password.
    </p>
    <Link v-if="!twoFactorEnabled" href="/two-factor" class="btn btn-primary">Set up two-factor authentication</Link>
    <BButton v-else variant="outline-danger" :disabled="disableTwoFactorForm.processing" @click="disableTwoFactor">
      Disable two-factor authentication
    </BButton>
  </BCard>
</template>
