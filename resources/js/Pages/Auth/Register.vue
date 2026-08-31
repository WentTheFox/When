<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { BAlert, BButton, BCard, BFormGroup, BFormInput } from 'bootstrap-vue-next';
import { ref } from 'vue';
import CenteredColumn from '../../Components/CenteredColumn.vue';
import PasswordField from '../../Components/PasswordField.vue';
import {
  deriveKeyFromPassphrase,
  deriveLoginVerifier,
  emptyKeyRing,
  encryptKeyRing,
  generateSalt,
} from '../../crypto';
import { useVault } from '../../dashboard/useVault';
import PublicLayout from '../../Layouts/PublicLayout.vue';

defineOptions({ layout: PublicLayout });

const { unlock } = useVault();

const props = defineProps<{
  code: string;
  inviterName: string | null;
  hasValidInvite: boolean;
}>();

const page = usePage();

const masterPassword = ref('');
const submitting = ref(false);
const error = ref('');

const form = useForm({
  id: '',
  invite_code: props.code,
  name: '',
  email: '',
  password: '',
  password_confirmation: '',
  passphrase_salt: '',
  key_ring_ciphertext: '',
});

async function submit(): Promise<void> {
  error.value = '';

  if (!masterPassword.value) {
    error.value = 'Please enter a master password.';
    return;
  }

  if (!form.name) {
    error.value = 'Please enter your name first.';
    return;
  }

  submitting.value = true;

  try {
    const salt = generateSalt();
    const { keyBytes } = await deriveKeyFromPassphrase(masterPassword.value, salt);
    const vaultKey = await crypto.subtle.importKey('raw', keyBytes, { name: 'AES-GCM' }, false, ['encrypt', 'decrypt']);

    form.passphrase_salt = salt;
    form.key_ring_ciphertext = await encryptKeyRing(vaultKey, emptyKeyRing());

    // The login-verifier salt (see resources/js/crypto/argon2.ts) is
    // derived from this id, not from the name or email — both of those
    // can be changed later, but the id never does.
    form.id = crypto.randomUUID();
    const verifier = await deriveLoginVerifier(masterPassword.value, form.id);
    form.password = verifier;
    form.password_confirmation = verifier;

    const passwordForVault = masterPassword.value;

    form.post('/register', {
      onFinish: () => { submitting.value = false; },
      // Same reasoning as Login.vue: registration already derived a vault
      // key above, but re-deriving via the normal unlock flow (rather than
      // reaching into vault.ts's internals to seed it directly) keeps this
      // on the same, single code path — and it's a session that's about to
      // need the vault anyway (first dashboard visit), so skip making a
      // brand new owner "unlock" something they just set the passphrase
      // for seconds ago.
      onSuccess: () => {
        unlock(passwordForVault).catch(() => {});
      },
    });
  } catch (e) {
    console.error(e);
    error.value = 'Something went wrong preparing your vault. Please try again.';
    submitting.value = false;
  }
}
</script>

<template>
  <Head :title="`Create your ${page.props.appName} account`" />

  <CenteredColumn size="medium">
    <BAlert v-if="page.props.isFirstUser" :model-value="true" variant="info">
      You're the first person signing up so there's no invite needed. Every account after
      yours will need one.
    </BAlert>
    <BAlert v-else-if="!hasValidInvite" :model-value="true" variant="warning">
      Registration is invite-only. You need a valid invite link to sign up.
    </BAlert>

    <BCard v-else>
      <h1 class="h3 mb-3 text-center">Create your <em>{{ page.props.appName }}</em> account</h1>

      <BAlert :model-value="Object.keys(form.errors).length > 0" variant="danger">
        <ul class="mb-0">
          <li v-for="(message, field) in form.errors" :key="field">{{ message }}</li>
        </ul>
      </BAlert>

      <form @submit.prevent="submit">
        <BFormGroup v-if="!page.props.isFirstUser" label="Invited by" label-for="invited_by" class="mb-3">
          <BFormInput id="invited_by" type="text" :model-value="inviterName" disabled />
        </BFormGroup>

        <BFormGroup label="Name" label-for="name" class="mb-3">
          <BFormInput id="name" v-model="form.name" type="text" pattern="[^@]+" required />
          <template #description>
            No <code>@</code> — you'll use this (or your email, if you add one) to log in.
          </template>
        </BFormGroup>

        <BFormGroup label="Email (optional)" label-for="email" class="mb-3">
          <BFormInput id="email" v-model="form.email" type="email" />
          <template #description>
            Only used to fetch your <a href="https://gravatar.com" target="_blank" rel="noopener">Gravatar</a>
            avatar and, if you set one, as an alternate way to log in. Stored
            encrypted, never shown to anyone. See the
            <a href="/about">security page</a> for details.
          </template>
        </BFormGroup>

        <BFormGroup label="Master password" label-for="master_password" class="mb-3">
          <PasswordField id="master_password" v-model="masterPassword" required />
          <template #description>
            This is your only password — it logs you in <strong>and</strong> unlocks your
            encrypted data (calendar, Connections). It never leaves your device in a form
            that could reveal it — not even we can see it. There is no password reset:
            if you lose it, you lose access to your account and your encrypted data,
            permanently.
          </template>
        </BFormGroup>

        <div class="text-danger small mb-2">{{ error }}</div>

        <BButton type="submit" variant="primary" class="w-100" :disabled="submitting">
          {{ submitting ? 'Preparing your vault…' : 'Create account' }}
        </BButton>
      </form>

      <p class="text-center mt-3 mb-0">
        <a href="/login">Already have an account? Log in</a>
      </p>
    </BCard>
  </CenteredColumn>
</template>
