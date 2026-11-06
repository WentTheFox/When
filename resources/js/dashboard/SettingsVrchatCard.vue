<script setup lang="ts">
/**
 * Settings.vue's "VRChat" card — connects the owner's own VRCX Headless
 * server (name/picture and time spent together) and picks which custom field
 * holds a connection's VRChat user id(s): an identifier or identifier list.
 *
 * Everything here is §0.2 tier (server-readable, APP_KEY-encrypted at rest).
 * Field labels are §0.1 E2EE, hence the vault gate. The API key is shown back
 * to its owner like the calendar URL (same SecretField): the server can decrypt
 * it either way, and it stays masked until revealed.
 */
import { router, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { BButton, BFormCheckbox, BFormGroup, BFormInput, BFormSelect } from 'bootstrap-vue-next';
import { computed, ref, watch } from 'vue';
import { decryptString } from '../crypto';
import SearchableSelect from '../Components/SearchableSelect.vue';
import SecretField from '../Components/SecretField.vue';
import { rawPreview } from './rawCiphertext';
import VaultGate from './VaultGate.vue';
import { useVault } from './useVault';
import { GithubMark } from '../Components/BrandIcons';

type Definition = { id: string; label_ciphertext: string; type: string };

const props = defineProps<{
  definitions: Definition[];
  /** The owner's connection sources (names are E2EE, decrypted below). */
  sources: { id: string; name_ciphertext: string; color_key: string | null }[];
  vrcx: {
    base_url: string | null;
    api_key: string | null;
    vrchat_user_id_definition_id: string | null;
    auto_create_friends: boolean;
    friends_source_id: string | null;
    synced_at: string | null;
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
  for (const source of props.sources) {
    try {
      labels.value[source.id] = await decryptString(await getRecordKey(source.id), source.name_ciphertext);
    } catch (error) {
      console.error(error);
      labels.value[source.id] = '(could not decrypt)';
    }
  }
}, { immediate: true });

const sourceOptions = computed(() => [
  { value: '', text: '(none)' },
  ...props.sources.map((s) => ({ value: s.id, text: labels.value[s.id] ?? rawPreview(s.name_ciphertext), swatch: s.color_key })),
]);

const fieldOptions = computed(() => [
  { value: '', text: '(none — no VRChat data is pulled)' },
  ...props.definitions.map((d) => ({
    value: d.id,
    text: `${labels.value[d.id] ?? rawPreview(d.label_ciphertext)} (${d.type === 'identifier_list' ? 'list' : 'single'})`,
  })),
]);

const form = useForm({
  vrcx_base_url: props.vrcx.base_url ?? '',
  vrcx_api_key: props.vrcx.api_key ?? '',
  vrchat_user_id_definition_id: props.vrcx.vrchat_user_id_definition_id ?? '',
  vrcx_auto_create_friends: props.vrcx.auto_create_friends,
  vrcx_friends_source_id: props.vrcx.friends_source_id ?? '',
});

function save(): void {
  form.transform((data) => ({
    vrcx_base_url: data.vrcx_base_url.trim() || null,
    // Blank keeps the stored key (Disconnect removes it).
    vrcx_api_key: data.vrcx_api_key || null,
    vrchat_user_id_definition_id: data.vrchat_user_id_definition_id || null,
    vrcx_auto_create_friends: data.vrcx_auto_create_friends,
    vrcx_friends_source_id: data.vrcx_friends_source_id || null,
  })).patch('/settings/vrcx', {
    preserveScroll: true,
    onSuccess: () => {
      form.defaults();
    },
  });
}

function disconnect(): void {
  router.delete('/settings/vrcx', { preserveScroll: true });
}

const syncing = ref(false);
const syncResult = ref<{ ok: boolean; text: string; warnings?: string[] } | null>(null);

async function syncNow(): Promise<void> {
  syncing.value = true;
  syncResult.value = null;

  try {
    const { data } = await axios.post('/settings/vrcx/sync');
    const linked = Object.values<unknown[]>(data.profiles ?? {}).reduce((n, list) => n + list.length, 0);
    syncResult.value = {
      ok: true,
      text: data.ran ? `Synced ${linked} linked account${linked === 1 ? '' : 's'}.` : 'A sync is already running.',
      warnings: data.warnings ?? [],
    };
  } catch (error) {
    syncResult.value = {
      ok: false,
      text: axios.isAxiosError(error) ? (error.response?.data?.message ?? 'Sync failed.') : 'Sync failed.',
    };
  } finally {
    syncing.value = false;
  }
}

const hasSavedKey = computed(() => !!props.vrcx.api_key);
const connected = computed(() => !!props.vrcx.base_url && hasSavedKey.value);
const ready = computed(() => connected.value && props.vrcx.vrchat_user_id_definition_id !== null);
const syncedAt = computed(() => (props.vrcx.synced_at ? new Date(props.vrcx.synced_at).toLocaleString() : null));
</script>

<template>
  <section id="vrchat">
    <h2 class="h5 mb-3">VRChat</h2>
    <p class="small text-muted">
      Connect your own <strong>VRCX Headless server</strong> <a href="https://github.com/WentTheFox/VRCX-Headless" target="_blank" rel="noopener"><GithubMark /><span class="visually-hidden">GitHub</span></a>
      to show a connection's VRChat name and profile picture, and to total the time you've spent together in VRChat. Mark one identifier (or identifier list) custom field as holding
      VRChat user IDs (<code>usr_…</code>) and every connection with a value there is looked up. VRCX Headless only reports the last 90 days;
      what's already been pulled is kept, so totals keep growing. Needs an API key with the <code>profile:read</code>,
      <code>users:read</code> and <code>playtime:read</code> scopes; add <code>notes:read</code> to also carry your private VRChat notes
      over to your connections' Notes field (you press Import on the Connections page).
    </p>
    <p class="small text-muted">
      This data is <strong>not</strong> end-to-end encrypted: the server reads it to talk to VRCX Headless, and stores it
      (and your URL and key) encrypted at rest with the app key.
    </p>

    <VaultGate compact>
      <form @submit.prevent="save">
        <BFormGroup label="VRCX Headless server URL" description="The server's address; pasting the openapi.json link works too." class="mb-3" style="max-width: 28rem;">
          <BFormInput v-model="form.vrcx_base_url" type="url" size="sm" placeholder="https://vrcx-api.example" autocomplete="off" />
          <div v-if="form.errors.vrcx_base_url" class="text-danger small">{{ form.errors.vrcx_base_url }}</div>
        </BFormGroup>

        <SecretField
          id="vrcx_api_key"
          v-model="form.vrcx_api_key"
          label="API key"
          noun="API key"
          :configured="hasSavedKey"
          description="Looks like vrcx_<id>_<secret>."
          :error="form.errors.vrcx_api_key"
          autocomplete="off"
          class="vrcx-secret"
          style="max-width: 28rem;"
        />

        <div id="vrchat-user-id" class="mb-3">
          <p v-if="!definitions.length" class="small mb-0">
            No identifier or identifier list fields yet — add one under Connections → Custom attributes to hold VRChat user IDs.
          </p>
          <BFormGroup v-else label="VRChat user ID field" style="max-width: 28rem;">
            <BFormSelect v-model="form.vrchat_user_id_definition_id" :options="fieldOptions" size="sm" />
            <div v-if="form.errors.vrchat_user_id_definition_id" class="text-danger small">{{ form.errors.vrchat_user_id_definition_id }}</div>
          </BFormGroup>
        </div>

        <div id="vrchat-friends" class="mb-3" style="max-width: 36rem;">
          <BFormCheckbox v-model="form.vrcx_auto_create_friends" :disabled="!form.vrchat_user_id_definition_id">
            List VRChat friends who have no connection yet
          </BFormCheckbox>
          <div class="form-text">
            Every VRChat friend without a connection (existing friends included) is listed on the Connections page and stays
            there until you deal with it: create a connection named after them with their ID in the field above, add the ID to a
            connection you already have, or dismiss them. Each friend comes with a suggestion, found by matching their VRChat name
            against your connections' names and highlight tokens. Needs the <code>friends:read</code> scope. Names are end-to-end
            encrypted, so your browser does the matching and creating: unlock your vault on the Connections page to act on the list.
          </div>
          <div v-if="form.errors.vrcx_auto_create_friends" class="text-danger small">{{ form.errors.vrcx_auto_create_friends }}</div>
          <BFormGroup v-if="form.vrcx_auto_create_friends" label="Source for these connections" description="Tied to each connection when it is first created; you can change it on the connection afterwards." class="mt-2" style="max-width: 28rem;">
            <SearchableSelect v-model="form.vrcx_friends_source_id" :options="sourceOptions" aria-label="Source for these connections" />
            <div v-if="form.errors.vrcx_friends_source_id" class="text-danger small">{{ form.errors.vrcx_friends_source_id }}</div>
          </BFormGroup>
        </div>

        <div class="d-flex flex-wrap gap-2 align-items-center">
          <BButton type="submit" size="sm" variant="primary" :disabled="form.processing || !form.isDirty">Save</BButton>
          <BButton size="sm" variant="outline-secondary" :disabled="syncing || form.isDirty || !ready" :title="form.isDirty ? 'Save first' : !ready ? 'Set the server, key and field first' : undefined" @click="syncNow">
            {{ syncing ? 'Syncing…' : 'Sync now' }}
          </BButton>
          <BButton v-if="connected" size="sm" variant="outline-danger" @click="disconnect">Disconnect</BButton>
          <span v-if="syncedAt" class="small text-muted">Last synced {{ syncedAt }}</span>
        </div>
        <div v-if="syncResult" class="small mt-2" :class="syncResult.ok ? 'text-success' : 'text-danger'" role="status">{{ syncResult.text }}</div>
        <div v-for="warning in syncResult?.warnings ?? []" :key="warning" class="small mt-1 text-warning" role="status">{{ warning }}</div>
      </form>
    </VaultGate>
  </section>
</template>
