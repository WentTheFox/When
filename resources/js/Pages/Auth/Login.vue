<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { BAlert, BButton, BCard, BFormCheckbox, BFormGroup, BFormInput } from 'bootstrap-vue-next';
import { ref } from 'vue';
import CenteredColumn from '../../Components/CenteredColumn.vue';
import PasswordField from '../../Components/PasswordField.vue';
import { deriveLoginVerifier } from '../../crypto';
import { useVault } from '../../dashboard/useVault';
import { autoUnlockPending } from '../../dashboard/vaultModal';
import PublicLayout from '../../Layouts/PublicLayout.vue';

const { unlock } = useVault();

defineOptions({ layout: PublicLayout });

const masterPassword = ref('');
const submitting = ref(false);
const error = ref('');

const form = useForm({
  identifier: '',
  password: '',
  remember: false,
});

interface LookupResponse {
  id: string;
  saltVersion: 'id' | 'email';
}

async function submit(): Promise<void> {
  error.value = '';

  if (!form.identifier || !masterPassword.value) {
    error.value = 'Please enter your name or email, and your master password.';
    return;
  }

  submitting.value = true;

  try {
    // The login-verifier salt (resources/js/crypto/argon2.ts) is derived
    // from the account's immutable id, not from whatever was typed here —
    // name/email can be changed later, the id never does. This tiny
    // round-trip resolves the typed identifier to that id (and to which
    // salt scheme this particular account still uses — see
    // AuthenticatedSessionController::lookup()) before the verifier can be
    // computed at all.
    const { data: lookup } = await axios.post<LookupResponse>('/login/lookup', {
      identifier: form.identifier,
    });

    // A not-yet-migrated account's stored verifier is salted from its
    // *email*, not from whatever was typed here — so unlike the 'id'
    // scheme, this one only works if the identifier typed is actually the
    // email. Logging in that way is exactly what triggers the one-time
    // migration below, after which name login works for this account too.
    if (lookup.saltVersion === 'email' && !form.identifier.includes('@')) {
      error.value = 'This account needs one login with its email to enable name login. Please use your email this time.';
      submitting.value = false;
      return;
    }

    const legacySaltBasis = lookup.saltVersion === 'email' ? form.identifier : null;
    const saltBasis = legacySaltBasis ?? lookup.id;

    form.password = await deriveLoginVerifier(masterPassword.value, saltBasis);
    const passwordForVault = masterPassword.value;

    // Set synchronously, before post() — guaranteed true before Inertia can
    // possibly swap to a vault-gated dashboard page, unlike setting it from
    // onSuccess below, which Inertia only fires *after* that page (and its
    // VaultGate's own requestUnlock() call) has already mounted. See
    // vaultModal.ts's autoUnlockPending doc comment for the full reasoning.
    autoUnlockPending.value = true;

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
        unlock(passwordForVault).catch(() => {}).finally(() => { autoUnlockPending.value = false; });

        // Transparent one-time migration off the legacy email-salted
        // verifier (see the verifier_salt_version migration and
        // AuthenticatedSessionController::migrateVerifier()) — this is the
        // only moment the master password is available after a successful
        // login, so it's now or never for this account.
        if (legacySaltBasis !== null) {
          deriveLoginVerifier(masterPassword.value, lookup.id)
            .then((verifier) => axios.post('/account/migrate-verifier', { verifier }))
            .catch(() => {});
        }
      },
      onError: () => { autoUnlockPending.value = false; },
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

  <CenteredColumn size="narrow">
    <BCard>
      <h1 class="h3 mb-4 text-center">Log in</h1>

      <BAlert :model-value="!!form.errors.identifier" variant="danger">{{ form.errors.identifier }}</BAlert>

      <form @submit.prevent="submit">
        <BFormGroup label="Name or email" label-for="identifier" class="mb-3">
          <BFormInput id="identifier" v-model="form.identifier" type="text" required autofocus />
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
  </CenteredColumn>
</template>
