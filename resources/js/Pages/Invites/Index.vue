<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { BButton, BCard, BFormGroup, BFormInput } from 'bootstrap-vue-next';
import DashboardLayout from '../../Layouts/DashboardLayout.vue';

defineOptions({ layout: DashboardLayout });

interface InviteRow {
  id: string;
  code: string;
  redemption_count: number;
  max_uses: number | null;
  expires_at: string | null;
  source: string;
}

defineProps<{ invites: InviteRow[] }>();

const form = useForm({
  max_uses: null as number | null,
  expires_in_days: null as number | null,
});

function create(): void {
  form.post('/invites', { onSuccess: () => form.reset() });
}

function revoke(invite: InviteRow): void {
  router.delete(`/invites/${invite.id}`);
}
</script>

<template>
  <BCard class="mb-4">
    <h1 class="h3 mb-4">Invites</h1>

    <h2 class="h5 mb-3">Create an invite</h2>
    <form class="row align-items-end" @submit.prevent="create">
      <div class="col-md-4">
        <BFormGroup label="Max uses" class="mb-3">
          <BFormInput v-model.number="form.max_uses" type="number" min="1" placeholder="Unlimited" />
        </BFormGroup>
      </div>
      <div class="col-md-4">
        <BFormGroup label="Expires in days" class="mb-3">
          <BFormInput v-model.number="form.expires_in_days" type="number" min="1" placeholder="Never" />
        </BFormGroup>
      </div>
      <div class="col-md-4 mb-3">
        <BButton type="submit" variant="primary" class="w-100" :disabled="form.processing">Create invite</BButton>
      </div>
    </form>
  </BCard>

  <BCard body-class="p-0">
    <div class="table-responsive">
      <table class="table table-sm mb-0">
        <thead>
          <tr>
            <th>Code</th>
            <th>Uses</th>
            <th>Max uses</th>
            <th>Expires</th>
            <th>Source</th>
            <th />
          </tr>
        </thead>
        <tbody>
          <tr v-for="invite in invites" :key="invite.id">
            <td><a :href="`/register?code=${invite.code}`">{{ invite.code }}</a></td>
            <td>{{ invite.redemption_count }}</td>
            <td>{{ invite.max_uses ?? '∞' }}</td>
            <td>{{ invite.expires_at ?? 'never' }}</td>
            <td>{{ invite.source }}</td>
            <td>
              <BButton variant="outline-danger" size="sm" @click="revoke(invite)">Revoke</BButton>
            </td>
          </tr>
          <tr v-if="invites.length === 0">
            <td colspan="6" class="text-muted">No invites yet.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </BCard>
</template>
