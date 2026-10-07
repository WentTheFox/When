<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { BAlert, BButton, BCard, BFormGroup, BFormInput } from 'bootstrap-vue-next';
import { computed, ref } from 'vue';
import { getPasskeyAssertion, isPasskeyCancellation, passkeysSupported, type RequestOptionsJson } from '../../auth/webauthn';
import CenteredColumn from '../../Components/CenteredColumn.vue';
import PublicLayout from '../../Layouts/PublicLayout.vue';

defineOptions({ layout: PublicLayout });

const props = defineProps<{
  hasTotp: boolean;
  hasPasskeys: boolean;
}>();

const form = useForm({
  code: '',
  recovery_code: '',
});

const canUsePasskey = computed(() => props.hasPasskeys && passkeysSupported());
const passkeyBusy = ref(false);
const passkeyError = ref('');

function submit(): void {
  form.post('/two-factor-challenge');
}

async function usePasskey(): Promise<void> {
  passkeyError.value = '';
  passkeyBusy.value = true;

  try {
    const { data: options } = await axios.post<RequestOptionsJson>('/two-factor-challenge/passkey/options');
    const assertion = await getPasskeyAssertion(options);

    router.post('/two-factor-challenge/passkey', { ...assertion }, {
      onError: (errors) => {
        passkeyError.value = errors.passkey ?? 'That passkey could not be verified.';
      },
      onFinish: () => {
        passkeyBusy.value = false;
      },
    });
  } catch (e) {
    passkeyBusy.value = false;
    if (!isPasskeyCancellation(e)) {
      console.error(e);
      passkeyError.value = 'Something went wrong using your passkey. Please try again.';
    }
  }
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
      <BAlert :model-value="!!passkeyError" variant="danger">{{ passkeyError }}</BAlert>

      <template v-if="canUsePasskey">
        <BButton variant="primary" class="w-100" :disabled="passkeyBusy" @click="usePasskey">
          Use a passkey
        </BButton>
        <p v-if="hasTotp" class="text-muted small text-center my-3">Or enter a code instead:</p>
      </template>
      <p v-else-if="hasPasskeys && !hasTotp" class="text-muted small">
        This account's second factor is a passkey, but this browser doesn't support passkeys.
        You can still use a recovery code below.
      </p>

      <form @submit.prevent="submit">
        <BFormGroup v-if="hasTotp" label="Authenticator code" label-for="code" class="mb-3">
          <BFormInput
            id="code"
            v-model="form.code"
            type="text"
            inputmode="numeric"
            autocomplete="one-time-code"
            :autofocus="!canUsePasskey"
          />
        </BFormGroup>

        <p class="text-muted small">{{ hasTotp ? "Or, if you've lost your device:" : "Or, if you've lost your passkey:" }}</p>

        <BFormGroup label="Recovery code" label-for="recovery_code" class="mb-3">
          <BFormInput id="recovery_code" v-model="form.recovery_code" type="text" />
        </BFormGroup>

        <BButton type="submit" :variant="canUsePasskey ? 'outline-primary' : 'primary'" class="w-100" :disabled="form.processing">
          Verify
        </BButton>
      </form>
    </BCard>
  </CenteredColumn>
</template>
