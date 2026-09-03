<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { BAlert, BBadge, BButton, BCard, BFormGroup, BFormInput } from 'bootstrap-vue-next';
import { ref } from 'vue';
import PasswordField from '../../Components/PasswordField.vue';
import {
  DecryptionFailedError,
  deriveKeyFromPassphrase,
  deriveLoginVerifier,
  encryptKeyRing,
  generateSalt,
} from '../../crypto';
import { requestConfirm } from '../../dashboard/confirmModal';
import { requestConfirmation } from '../../dashboard/confirmPasswordModal';
import { decryptVaultKeyRingWithPassphrase } from '../../dashboard/vault';
import DashboardLayout from '../../Layouts/DashboardLayout.vue';
import type { SharedPageProps } from '../../sharedPageProps';

defineOptions({ layout: DashboardLayout });

const props = defineProps<{
  name: string;
  email: string | null;
  twoFactorEnabled: boolean;
}>();

const page = usePage<SharedPageProps>();

const nameForm = useForm({ name: props.name });
const emailForm = useForm({ email: props.email ?? '' });
const disableTwoFactorForm = useForm({});

function saveName(): void {
  nameForm.patch('/dashboard/account/name', { preserveScroll: true });
}

function saveEmail(): void {
  emailForm.patch('/dashboard/account/email', { preserveScroll: true });
}

async function disableTwoFactor(): Promise<void> {
  const confirmed = await requestConfirm({
    title: 'Disable two-factor authentication?',
    message: 'Turn off two-factor authentication for your account?',
    confirmText: 'Disable',
    variant: 'danger',
  });
  if (!confirmed) return;

  disableTwoFactorForm.delete('/two-factor', { preserveScroll: true });
}

interface LookupResponse {
  id: string;
  saltVersion: 'id' | 'email';
}

async function currentUserId(): Promise<string> {
  const name = page.props.auth.user?.name;
  const { data: lookup } = await axios.post<LookupResponse>('/login/lookup', { identifier: name });
  return lookup.id;
}

// --- Change master password ---------------------------------------------

const currentPassword = ref('');
const newPassword = ref('');
const newPasswordConfirmation = ref('');
const passwordChanging = ref(false);
const passwordError = ref('');
const passwordChanged = ref(false);

async function changePassword(): Promise<void> {
  passwordError.value = '';
  passwordChanged.value = false;

  if (!currentPassword.value || !newPassword.value) {
    passwordError.value = 'Please fill in all fields.';
    return;
  }

  if (newPassword.value !== newPasswordConfirmation.value) {
    passwordError.value = 'New passwords do not match.';
    return;
  }

  passwordChanging.value = true;

  try {
    // Decrypting the current key ring with the re-entered current password
    // IS the "is this really your current password" check — a wrong
    // password can't produce a ring that decrypts, since AES-GCM is
    // authenticated. No separate server round-trip needed before this.
    const keyRing = await decryptVaultKeyRingWithPassphrase(currentPassword.value);

    const newSalt = generateSalt();
    const { keyBytes } = await deriveKeyFromPassphrase(newPassword.value, newSalt);
    const newVaultKey = await crypto.subtle.importKey('raw', keyBytes, { name: 'AES-GCM' }, false, ['encrypt', 'decrypt']);
    const newKeyRingCiphertext = await encryptKeyRing(newVaultKey, keyRing);

    const userId = await currentUserId();
    // The server also re-proves current-password possession itself
    // (ConfirmsPassword's Hash::check) rather than trusting that only an
    // honest client could have gotten this far client-side — a forged
    // request straight at this endpoint (stolen session, no master
    // password knowledge) would otherwise be able to overwrite the vault's
    // key ring outright. So the *current* password's verifier goes along
    // as `password`, same field name/shape the export/delete confirm flow
    // uses, alongside the *new* password's derived values.
    const currentVerifier = await deriveLoginVerifier(currentPassword.value, userId);
    const newVerifier = await deriveLoginVerifier(newPassword.value, userId);

    await axios.put('/dashboard/account/password', {
      password: currentVerifier,
      passphrase_salt: newSalt,
      key_ring_ciphertext: newKeyRingCiphertext,
      verifier: newVerifier,
    });

    passwordChanged.value = true;
    currentPassword.value = '';
    newPassword.value = '';
    newPasswordConfirmation.value = '';
  } catch (e) {
    if (e instanceof DecryptionFailedError) {
      passwordError.value = 'Current password is incorrect.';
    } else if (axios.isAxiosError(e) && e.response?.status === 422) {
      // The client-side decrypt above already proves current-password
      // possession in the overwhelmingly common case — this only fires if
      // the server's own Hash::check disagrees (e.g. a password changed
      // from another session mid-flight), so the same message applies.
      passwordError.value = 'Current password is incorrect.';
    } else {
      console.error(e);
      passwordError.value = 'Something went wrong. Please try again.';
    }
  } finally {
    passwordChanging.value = false;
  }
}

// --- Data export -----------------------------------------------------------

const exportFormRef = ref<HTMLFormElement | null>(null);
const exportPasswordRef = ref<HTMLInputElement | null>(null);
const exportError = ref('');

// A real browser form submission, not axios/fetch/Inertia — the response is
// a streamed zip download, and only a genuine navigation gets the browser's
// native Save/Open handling for that without buffering the whole file in JS
// memory first.
const csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';

async function exportData(): Promise<void> {
  exportError.value = '';

  const verifier = await requestConfirmation();
  if (verifier === null) return;

  if (!exportFormRef.value || !exportPasswordRef.value) return;

  exportPasswordRef.value.value = verifier;
  exportFormRef.value.submit();
}

// --- Delete account ----------------------------------------------------

const deleteForm = useForm({ password: '' });

async function deleteAccount(): Promise<void> {
  const confirmed = await requestConfirm({
    title: 'Delete your account?',
    message: 'You will be logged out immediately, and this cannot be undone once started.',
    confirmText: 'Delete my account',
    variant: 'danger',
  });
  if (!confirmed) return;

  const verifier = await requestConfirmation();
  if (verifier === null) return;

  deleteForm.password = verifier;
  deleteForm.delete('/dashboard/account');
}
</script>

<template>
  <Head title="Account" />

  <BCard class="mb-4">
    <h1 class="h3 mb-4">Account</h1>

    <h2 class="h5 mb-3">Name</h2>
    <p class="small text-muted mb-3">
      No <code>@</code>. Used to log in, alongside your email if you have one.
    </p>
    <BAlert :model-value="!!nameForm.recentlySuccessful" variant="success" dismissible>Name updated.</BAlert>
    <form class="mb-4" @submit.prevent="saveName">
      <BFormGroup label-for="name" :state="nameForm.errors.name ? false : null" :invalid-feedback="nameForm.errors.name" class="mb-3">
        <BFormInput id="name" v-model="nameForm.name" type="text" pattern="[^@]+" required />
      </BFormGroup>
      <BButton type="submit" variant="primary" :disabled="nameForm.processing">Save name</BButton>
    </form>

    <h2 class="h5 mb-3">Email</h2>
    <p class="small text-muted mb-3">
      Optional — only used to fetch your <a href="https://gravatar.com" target="_blank" rel="noopener">Gravatar</a>
      avatar and, if set, as an alternate way to log in. Stored encrypted, never shown to
      anyone. See the <a href="/about">security page</a> for details.
    </p>
    <BAlert :model-value="!!emailForm.recentlySuccessful" variant="success" dismissible>Email updated.</BAlert>
    <form class="mb-4" @submit.prevent="saveEmail">
      <BFormGroup label-for="email" :state="emailForm.errors.email ? false : null" :invalid-feedback="emailForm.errors.email" class="mb-3">
        <BFormInput id="email" v-model="emailForm.email" type="email" />
      </BFormGroup>
      <BButton type="submit" variant="primary" :disabled="emailForm.processing">Save email</BButton>
    </form>

    <h2 class="h5 mb-3">
      Two-factor authentication
      <BBadge v-if="twoFactorEnabled" variant="success" class="ms-1">Enabled</BBadge>
      <BBadge v-else variant="secondary" class="ms-1">Not enabled</BBadge>
    </h2>
    <p class="small text-muted mb-3">
      Adds a one-time code from an authenticator app to every login, separate from your
      master password.
    </p>

    <BAlert :model-value="!!page.props.flash?.recoveryCodes" variant="warning">
      <strong>Save these recovery codes somewhere safe</strong> — each one works once,
      and this is the only time they're shown:
      <ul class="mb-0 mt-2">
        <li v-for="recoveryCode in page.props.flash?.recoveryCodes" :key="recoveryCode">
          <code>{{ recoveryCode }}</code>
        </li>
      </ul>
    </BAlert>

    <Link v-if="!twoFactorEnabled" href="/two-factor" class="btn btn-primary mb-4 d-inline-block">
      Set up two-factor authentication
    </Link>
    <BButton v-else variant="outline-danger" class="mb-4" :disabled="disableTwoFactorForm.processing" @click="disableTwoFactor">
      Disable two-factor authentication
    </BButton>

    <h2 class="h5 mb-3">Change master password</h2>
    <p class="small text-muted mb-3">
      Your master password logs you in <strong>and</strong> unlocks your encrypted
      data. Changing it re-encrypts your vault's key ring under a freshly-derived key —
      nothing you've stored needs to be re-entered.
    </p>
    <BAlert :model-value="passwordChanged" variant="success" dismissible>Master password updated.</BAlert>
    <form class="mb-4" @submit.prevent="changePassword">
      <BFormGroup label="Current master password" label-for="current_password" class="mb-3">
        <PasswordField id="current_password" v-model="currentPassword" required />
      </BFormGroup>
      <BFormGroup label="New master password" label-for="new_password" class="mb-3">
        <PasswordField id="new_password" v-model="newPassword" required />
      </BFormGroup>
      <BFormGroup label="Confirm new master password" label-for="new_password_confirmation" class="mb-3">
        <PasswordField id="new_password_confirmation" v-model="newPasswordConfirmation" required />
      </BFormGroup>
      <div v-if="passwordError" class="text-danger small mb-2">{{ passwordError }}</div>
      <BButton type="submit" variant="primary" :disabled="passwordChanging">
        {{ passwordChanging ? 'Updating…' : 'Update master password' }}
      </BButton>
    </form>

    <h2 class="h5 mb-3 text-danger">Danger zone</h2>

    <div class="mb-4">
      <h3 class="h6">Download my data</h3>
      <p class="small text-muted mb-2">
        Includes a full copy of your account data, some of it still encrypted —
        instructions on how to decrypt with your master password are in the download.
        Limited to 5 downloads per day.
      </p>
      <div v-if="exportError" class="text-danger small mb-2">{{ exportError }}</div>
      <BButton variant="outline-secondary" @click="exportData">Download my data</BButton>
      <form ref="exportFormRef" method="POST" action="/dashboard/account/export" class="d-none">
        <input type="hidden" name="_token" :value="csrfToken">
        <input ref="exportPasswordRef" type="hidden" name="password" value="">
      </form>
    </div>

    <div>
      <h3 class="h6">Delete my account</h3>
      <p class="small text-muted mb-2">
        Deletes your account. You'll be logged out immediately. Your data is
        retained for 48 hours as an internal safety buffer, then permanently
        erased — this isn't a way to undo the deletion once started.
      </p>
      <BButton variant="outline-danger" :disabled="deleteForm.processing" @click="deleteAccount">
        Delete my account
      </BButton>
    </div>
  </BCard>
</template>
