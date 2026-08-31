<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { BAlert, BBadge, BButton, BCard, BFormGroup, BFormInput } from 'bootstrap-vue-next';
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

function disableTwoFactor(): void {
  if (!confirm('Turn off two-factor authentication for your account?')) return;
  disableTwoFactorForm.delete('/two-factor', { preserveScroll: true });
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

    <Link v-if="!twoFactorEnabled" href="/two-factor" class="btn btn-primary">Set up two-factor authentication</Link>
    <BButton v-else variant="outline-danger" :disabled="disableTwoFactorForm.processing" @click="disableTwoFactor">
      Disable two-factor authentication
    </BButton>
  </BCard>
</template>
