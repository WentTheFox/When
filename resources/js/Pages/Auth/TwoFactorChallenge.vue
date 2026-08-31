<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { BAlert, BButton, BCard, BFormGroup, BFormInput } from 'bootstrap-vue-next';
import CenteredColumn from '../../Components/CenteredColumn.vue';
import PublicLayout from '../../Layouts/PublicLayout.vue';

defineOptions({ layout: PublicLayout });

const form = useForm({
  code: '',
  recovery_code: '',
});

function submit(): void {
  form.post('/two-factor-challenge');
}
</script>

<template>
  <Head title="Two-factor verification" />

  <CenteredColumn size="narrow">
    <BCard>
      <h1 class="h3 mb-4 text-center">Two-factor verification</h1>

      <BAlert :model-value="Object.keys(form.errors).length > 0" variant="danger">
        <ul class="mb-0">
          <li v-for="(message, field) in form.errors" :key="field">{{ message }}</li>
        </ul>
      </BAlert>

      <form @submit.prevent="submit">
        <BFormGroup label="Authenticator code" label-for="code" class="mb-3">
          <BFormInput
            id="code"
            v-model="form.code"
            type="text"
            inputmode="numeric"
            autocomplete="one-time-code"
            autofocus
          />
        </BFormGroup>

        <p class="text-muted small">Or, if you've lost your device:</p>

        <BFormGroup label="Recovery code" label-for="recovery_code" class="mb-3">
          <BFormInput id="recovery_code" v-model="form.recovery_code" type="text" />
        </BFormGroup>

        <BButton type="submit" variant="primary" class="w-100" :disabled="form.processing">Verify</BButton>
      </form>
    </BCard>
  </CenteredColumn>
</template>
