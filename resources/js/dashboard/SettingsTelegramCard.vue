<script setup lang="ts">
/**
 * Settings.vue's "Telegram" card — logs the owner in to their own Telegram account (phone, the code Telegram
 * sends, and the two-step verification password if there is one) so their contacts can be listed on the
 * Connections page and linked to connections, and picks which custom field holds a connection's Telegram user
 * id(s): an identifier or identifier list. Only shown when the operator set up the optional Telegram sidecar.
 *
 * Telegram offers no OAuth-style "read my contacts" permission: only a logged-in user account can read them. So
 * this is a real account login, and the card says so plainly. §0.2 tier (server-readable, APP_KEY-encrypted at
 * rest): the server keeps the resulting session and fetches the contact list itself. The phone number, code and
 * password go to the server once, to log in, and are never stored. Field labels are §0.1 E2EE, hence the vault gate.
 */
import { router, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { BButton, BFormGroup, BFormInput, BFormSelect } from 'bootstrap-vue-next';
import { computed, ref, watch } from 'vue';
import { decryptString } from '../crypto';
import { rawPreview } from './rawCiphertext';
import { useVault } from './useVault';
import VaultGate from './VaultGate.vue';

type Definition = { id: string; label_ciphertext: string; type: string };

const props = defineProps<{
  definitions: Definition[];
  telegram: {
    available: boolean;
    connected: boolean;
    account_name: string | null;
    synced_at: string | null;
    user_id_definition_id: string | null;
  };
}>();

const { vaultUnlocked, getRecordKey } = useVault();

const labels = ref<Record<string, string>>({});
watch(vaultUnlocked, async (unlocked) => {
  if (!unlocked) return;
  for (const definition of props.definitions) {
    try {
      labels.value[definition.id] = await decryptString(await getRecordKey(definition.id), definition.label_ciphertext);
    } catch (error) {
      console.error(error);
      labels.value[definition.id] = '(could not decrypt)';
    }
  }
}, { immediate: true });

const fieldOptions = computed(() => [
  { value: '', text: '(none — no contacts are pulled)' },
  ...props.definitions.map((d) => ({
    value: d.id,
    text: `${labels.value[d.id] ?? rawPreview(d.label_ciphertext)} (${d.type === 'identifier_list' ? 'list' : 'single'})`,
  })),
]);

const form = useForm({ telegram_user_id_definition_id: props.telegram.user_id_definition_id ?? '' });

function saveField(): void {
  form.transform((data) => ({ telegram_user_id_definition_id: data.telegram_user_id_definition_id || null })).put('/settings/telegram', {
    preserveScroll: true,
    onSuccess: () => form.defaults(),
  });
}

// The login: phone -> code -> (password). The state lives on the server between steps; the browser only knows which step it is on.
type Step = 'phone' | 'code' | 'password';
const step = ref<Step>('phone');
const phone = ref('');
const code = ref('');
const password = ref('');
const busy = ref(false);
const message = ref<{ ok: boolean; text: string } | null>(null);

function errorText(error: unknown, fallback: string): string {
  if (!axios.isAxiosError(error)) return fallback;

  const data = error.response?.data;
  return data?.message ?? (data?.errors ? (Object.values(data.errors).flat()[0] as string) : fallback);
}

async function startLogin(): Promise<void> {
  busy.value = true;
  message.value = null;

  try {
    await axios.post('/settings/telegram/login/start', { phone: phone.value });
    step.value = 'code';
    message.value = { ok: true, text: 'Telegram sent a code to your Telegram app (or by SMS). Enter it below.' };
  } catch (error) {
    message.value = { ok: false, text: errorText(error, 'Could not start the login.') };
  } finally {
    busy.value = false;
  }
}

async function submitStep(path: 'code' | 'password', body: Record<string, string>): Promise<void> {
  busy.value = true;
  message.value = null;

  try {
    const { data } = await axios.post(`/settings/telegram/login/${path}`, body);

    if (data.needs_password) {
      step.value = 'password';
      message.value = { ok: true, text: 'This account has two-step verification. Enter its password.' };
    } else {
      finished();
    }
  } catch (error) {
    message.value = { ok: false, text: errorText(error, 'Login failed.') };
  } finally {
    busy.value = false;
  }
}

function finished(): void {
  // The secrets are not kept in the page a moment longer than needed.
  phone.value = '';
  code.value = '';
  password.value = '';
  step.value = 'phone';
  router.reload({ only: ['telegram'] });
}

function disconnect(): void {
  router.delete('/settings/telegram', { preserveScroll: true });
}

const syncing = ref(false);
const syncResult = ref<{ ok: boolean; text: string } | null>(null);

async function syncNow(): Promise<void> {
  syncing.value = true;
  syncResult.value = null;

  try {
    const { data } = await axios.post('/settings/telegram/sync');
    syncResult.value = {
      ok: true,
      text: data.ran
        ? `Synced. ${data.pending_friends} contact${data.pending_friends === 1 ? '' : 's'} waiting on the Connections page.`
        : 'A sync is already running.',
    };
    router.reload({ only: ['telegram'] });
  } catch (error) {
    syncResult.value = { ok: false, text: errorText(error, 'Sync failed.') };

    // Telegram ended the session: the card must show the login again.
    if (axios.isAxiosError(error) && error.response?.data?.reauth) router.reload({ only: ['telegram'] });
  } finally {
    syncing.value = false;
  }
}

const hasField = computed(() => props.telegram.user_id_definition_id !== null);
const syncedAt = computed(() => (props.telegram.synced_at ? new Date(props.telegram.synced_at).toLocaleString() : null));
</script>

<template>
  <section id="telegram">
    <h2 class="h5 mb-3">Telegram</h2>
    <p class="small text-muted">
      Log in to your Telegram account to list your <strong>contacts</strong> on the Connections page. Each one comes with a suggestion,
      found by matching their name and username against your connections, and you decide: create a connection, add their Telegram user ID to
      one you already have, or dismiss them. Contacts who are already on a connection (by user ID) are skipped. What comes along, where
      Telegram shares it: name, username, phone number, profile photo, whether you are mutual contacts and whether they have Premium.
    </p>
    <p class="small text-muted">
      Once a contact is on a connection, their <strong>name and username</strong> are also added to that connection's highlight tokens (when
      you've chosen a highlight tokens field under Share links). After a rename the new name is added next to the old one, and nothing is ever removed.
    </p>
    <p class="small text-muted">
      In the background, opening Connections only picks up <strong>new</strong> contacts we are not tracking yet; it never refreshes anyone's name,
      phone or photo. To refresh those, press <strong>Sync now</strong> here.
    </p>
    <div class="alert alert-warning small" role="note">
      <strong>This is a real login, not a limited permission.</strong> Telegram has no "read my contacts only" access for apps: the only way
      to read a contact list is to be logged in as the account. So the server keeps a <strong>session for your whole Telegram account</strong>,
      encrypted at rest with the app key, and uses it to read your contacts (and nothing else). Anyone who could run the server could use it, and it
      is <strong>not</strong> end-to-end encrypted. Your phone number, the code and the password are sent once to log in and are not stored.
      You can disconnect at any time: that logs the session out at Telegram, and you can also end it from Telegram's own Devices list.
      Your connections' names stay end-to-end encrypted, so your browser does the matching and creating.
    </div>

    <VaultGate compact>
      <form id="telegram-user-id" class="mb-3" @submit.prevent="saveField">
        <p v-if="!definitions.length" class="small mb-0">
          No identifier or identifier list fields yet — add one under Connections → Custom attributes to hold Telegram user IDs.
        </p>
        <template v-else>
          <BFormGroup
            label="Telegram user ID field"
            description="Where a connection's Telegram user ID is kept. It has to be a different field from the VRChat and Discord ones."
            style="max-width: 28rem;"
          >
            <BFormSelect v-model="form.telegram_user_id_definition_id" :options="fieldOptions" size="sm" />
            <div v-if="form.errors.telegram_user_id_definition_id" class="text-danger small">{{ form.errors.telegram_user_id_definition_id }}</div>
          </BFormGroup>
          <BButton type="submit" size="sm" variant="primary" class="mt-2" :disabled="form.processing || !form.isDirty">Save field</BButton>
        </template>
      </form>

      <div v-if="telegram.connected" class="d-flex flex-wrap gap-2 align-items-center">
        <span class="small">Logged in as <strong>{{ telegram.account_name ?? 'your Telegram account' }}</strong>.</span>
        <BButton size="sm" variant="outline-secondary" :disabled="syncing || !hasField || form.isDirty" :title="!hasField ? 'Choose and save the Telegram user ID field first' : form.isDirty ? 'Save the field first' : undefined" @click="syncNow">
          {{ syncing ? 'Syncing…' : 'Sync now' }}
        </BButton>
        <BButton size="sm" variant="outline-danger" @click="disconnect">Disconnect</BButton>
        <span v-if="syncedAt" class="small text-muted">Last synced {{ syncedAt }}</span>
      </div>

      <form v-else class="d-flex flex-column gap-2" style="max-width: 24rem;" autocomplete="off" @submit.prevent="step === 'phone' ? startLogin() : step === 'code' ? submitStep('code', { code }) : submitStep('password', { password })">
        <BFormGroup v-if="step === 'phone'" label="Phone number" label-for="telegram-phone" description="With the country code, like +30 690 000 0000. Telegram sends a code to it.">
          <BFormInput id="telegram-phone" v-model="phone" type="tel" size="sm" autocomplete="off" placeholder="+30 690 000 0000" required />
        </BFormGroup>
        <BFormGroup v-else-if="step === 'code'" label="Login code" label-for="telegram-code" description="From your Telegram app, or by SMS.">
          <BFormInput id="telegram-code" v-model="code" inputmode="numeric" size="sm" autocomplete="one-time-code" required />
        </BFormGroup>
        <BFormGroup v-else label="Two-step verification password" label-for="telegram-password" description="The cloud password you set in Telegram, not your phone's lock.">
          <BFormInput id="telegram-password" v-model="password" type="password" size="sm" autocomplete="off" required />
        </BFormGroup>
        <div class="d-flex gap-2">
          <BButton type="submit" size="sm" variant="primary" :disabled="busy">
            {{ busy ? 'Working…' : step === 'phone' ? 'Send code' : step === 'code' ? 'Log in' : 'Confirm' }}
          </BButton>
          <BButton v-if="step !== 'phone'" size="sm" variant="outline-secondary" :disabled="busy" @click="step = 'phone'; code = ''; password = ''; message = null">Start over</BButton>
        </div>
      </form>

      <div v-if="message" class="small mt-2" :class="message.ok ? 'text-muted' : 'text-danger'" role="status">{{ message.text }}</div>
      <div v-if="syncResult" class="small mt-2" :class="syncResult.ok ? 'text-success' : 'text-danger'" role="status">{{ syncResult.text }}</div>
    </VaultGate>
  </section>
</template>
