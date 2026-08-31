<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { BAlert, BButton, BCard, BFormGroup, BFormInput } from 'bootstrap-vue-next';
import QRCode from 'qrcode';
import { onMounted, ref } from 'vue';
import DashboardLayout from '../../Layouts/DashboardLayout.vue';

defineOptions({ layout: DashboardLayout });

const props = defineProps<{
  secret: string;
  qrCodeUrl: string;
}>();

const form = useForm({ code: '' });

function confirm(): void {
  form.post('/two-factor/confirm');
}

// qrCodeUrl is an otpauth:// URI (Google2FA::getQRCodeUrl), not an image —
// it has to be rendered into a scannable QR code client-side, not linked to
// or displayed as text.
const qrDataUrl = ref('');

onMounted(async () => {
  try {
    qrDataUrl.value = await QRCode.toDataURL(props.qrCodeUrl, { width: 220, margin: 1 });
  } catch (error) {
    console.error(error);
  }
});
</script>

<template>
  <Head title="Two-factor authentication" />

  <BCard>
    <h1 class="h3 mb-4 text-center">Two-factor authentication</h1>

    <div style="max-width: 28rem; margin: 0 auto;">
      <BAlert :model-value="Object.keys(form.errors).length > 0" variant="danger">
        <ul class="mb-0">
          <li v-for="(message, field) in form.errors" :key="field">{{ message }}</li>
        </ul>
      </BAlert>

      <p class="text-center">Scan this into your authenticator app, or enter the secret manually:</p>
      <div class="text-center mb-3">
        <img v-if="qrDataUrl" :src="qrDataUrl" width="220" height="220" alt="Two-factor setup QR code">
      </div>
      <p class="text-center"><code>{{ secret }}</code></p>

      <form @submit.prevent="confirm">
        <BFormGroup label="Enter the 6-digit code from your app to confirm" label-for="code" class="mb-3">
          <BFormInput id="code" v-model="form.code" type="text" inputmode="numeric" required />
        </BFormGroup>
        <BButton type="submit" variant="primary" class="w-100" :disabled="form.processing">Confirm</BButton>
      </form>
    </div>
  </BCard>
</template>
