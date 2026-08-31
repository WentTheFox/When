<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { BAlert, BButton, BCard, BFormGroup, BFormInput } from 'bootstrap-vue-next';
import PublicLayout from '../../Layouts/PublicLayout.vue';

defineOptions({ layout: PublicLayout });

defineProps<{
  secret: string;
  qrCodeUrl: string;
}>();

const page = usePage();

const form = useForm({ code: '' });

function confirm(): void {
  form.post('/two-factor/confirm');
}

function disable(): void {
  router.delete('/two-factor');
}
</script>

<template>
  <Head title="Two-factor authentication" />

  <div style="max-width: 28rem; margin: 0 auto;">
    <h1 class="h3 mb-4 text-center">Two-factor authentication</h1>

    <BAlert :model-value="!!page.props.flash?.recoveryCodes" variant="warning">
      <strong>Save these recovery codes somewhere safe</strong> — each one works once,
      and this is the only time they're shown:
      <ul class="mb-0 mt-2">
        <li v-for="recoveryCode in page.props.flash?.recoveryCodes" :key="recoveryCode">
          <code>{{ recoveryCode }}</code>
        </li>
      </ul>
    </BAlert>

    <BAlert :model-value="Object.keys(form.errors).length > 0" variant="danger">
      <ul class="mb-0">
        <li v-for="(message, field) in form.errors" :key="field">{{ message }}</li>
      </ul>
    </BAlert>

    <BCard class="mb-3">
      <p>Scan this into your authenticator app, or enter the secret manually:</p>
      <p><code>{{ secret }}</code></p>
      <p class="mb-0"><a :href="qrCodeUrl">{{ qrCodeUrl }}</a></p>
    </BCard>

    <BCard class="mb-3">
      <form @submit.prevent="confirm">
        <BFormGroup label="Enter the 6-digit code from your app to confirm" label-for="code" class="mb-3">
          <BFormInput id="code" v-model="form.code" type="text" inputmode="numeric" required />
        </BFormGroup>
        <BButton type="submit" variant="primary" class="w-100" :disabled="form.processing">Confirm</BButton>
      </form>
    </BCard>

    <BButton type="button" variant="outline-danger" class="w-100" @click="disable">
      Disable two-factor authentication
    </BButton>
  </div>
</template>
