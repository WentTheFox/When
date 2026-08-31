<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { BAlert, BButton, BCard, BFormCheckbox, BFormGroup, BFormInput } from 'bootstrap-vue-next';
import { ref } from 'vue';
import PasswordField from '../../Components/PasswordField.vue';
import { deriveLoginVerifier } from '../../crypto';
import { useVault } from '../../dashboard/useVault';
import PublicLayout from '../../Layouts/PublicLayout.vue';

const { unlock } = useVault();

defineOptions({ layout: PublicLayout });

const masterPassword = ref('');
const submitting = ref(false);
const error = ref('');

const form = useForm({
  email: '',
  password: '',
  remember: false,
});

async function submit(): Promise<void> {
  error.value = '';

  if (!form.email || !masterPassword.value) {
    error.value = 'Please enter your email and master password.';
    return;
  }

  submitting.value = true;

  try {
    form.password = await deriveLoginVerifier(masterPassword.value, form.email);
    const passwordForVault = masterPassword.value;

    form.post('/login', {
      onFinish: () => { submitting.value = false; },
      // Best-effort: lands here even when 2FA is pending (a "successful"
      // response, just not actually logged in yet) — /dashboard/vault would
      // 401 in that case, so this silently no-ops rather than blocking
      // navigation. The manual "Unlock your vault" prompt is still there as
      // a fallback either way; this just skips it on the common path,
      // reusing the master password already in memory from the form
      // submission instead of asking for it a second time right after.
      onSuccess: () => {
        unlock(passwordForVault).catch(() => {});
      },
    });
  } catch (e) {
    console.error(e);
    error.value = 'Something went wrong. Please try again.';
    submitting.value = false;
  }
}
</script>

<template>
  <Head title="Log in" />

  <div style="max-width: 24rem; margin: 0 auto;">
    <BCard>
      <h1 class="h3 mb-4 text-center">Log in</h1>

      <BAlert :model-value="!!form.errors.email" variant="danger">{{ form.errors.email }}</BAlert>

      <form @submit.prevent="submit">
        <BFormGroup label="Email" label-for="email" class="mb-3">
          <BFormInput id="email" v-model="form.email" type="email" required autofocus />
        </BFormGroup>

        <BFormGroup label="Master password" label-for="master_password" class="mb-3">
          <PasswordField id="master_password" v-model="masterPassword" required />
        </BFormGroup>

        <div class="text-danger small mb-2">{{ error }}</div>

        <BFormCheckbox id="remember" v-model="form.remember" class="mb-3">Remember me</BFormCheckbox>

        <BButton type="submit" variant="primary" class="w-100" :disabled="submitting">
          {{ submitting ? 'Signing in…' : 'Log in' }}
        </BButton>
      </form>
    </BCard>
  </div>
</template>
