<script setup lang="ts">
/**
 * Settings.vue's "Discord" card — connects the owner's own Discord account
 * (OAuth2: identify + relationships.read) so their friends can be listed on the
 * Connections page and linked to connections, and picks which custom field holds
 * a connection's Discord user id(s): an identifier or identifier list.
 *
 * §0.2 tier (server-readable, APP_KEY-encrypted at rest): the server holds the
 * OAuth tokens and fetches the friend list itself. Field labels are §0.1 E2EE,
 * hence the vault gate. The field is saved through the same endpoint as the
 * VRChat settings (it is shared with the Discord ids VRCX learns).
 */
import { router, useForm, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { BButton, BFormGroup, BFormSelect } from 'bootstrap-vue-next';
import { computed, ref, watch } from 'vue';
import { decryptString } from '../crypto';
import { rawPreview } from './rawCiphertext';
import { useVault } from './useVault';
import VaultGate from './VaultGate.vue';

type Definition = { id: string; label_ciphertext: string; type: string };

const props = defineProps<{
  definitions: Definition[];
  discord: {
    /** The server has a Discord app configured at all. */
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
  { value: '', text: '(none — no friends are pulled)' },
  ...props.definitions.map((d) => ({
    value: d.id,
    text: `${labels.value[d.id] ?? rawPreview(d.label_ciphertext)} (${d.type === 'identifier_list' ? 'list' : 'single'})`,
  })),
]);

const form = useForm({ discord_user_id_definition_id: props.discord.user_id_definition_id ?? '' });

function saveField(): void {
  form.transform((data) => ({ discord_user_id_definition_id: data.discord_user_id_definition_id || null })).patch('/settings/vrcx', {
    preserveScroll: true,
    onSuccess: () => form.defaults(),
  });
}

function disconnect(): void {
  router.delete('/settings/discord', { preserveScroll: true });
}

const syncing = ref(false);
const syncResult = ref<{ ok: boolean; text: string } | null>(null);

async function syncNow(): Promise<void> {
  syncing.value = true;
  syncResult.value = null;

  try {
    const { data } = await axios.post('/settings/discord/sync');
    syncResult.value = {
      ok: true,
      text: data.ran
        ? `Synced. ${data.pending_friends} friend${data.pending_friends === 1 ? '' : 's'} waiting on the Connections page.`
        : 'A sync is already running.',
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

// The OAuth callback reports a failed or refused sign-in as an error on the page.
const page = usePage<{ errors?: Record<string, string> }>();
const connectError = computed(() => page.props.errors?.discord ?? null);

const hasField = computed(() => props.discord.user_id_definition_id !== null);
const syncedAt = computed(() => (props.discord.synced_at ? new Date(props.discord.synced_at).toLocaleString() : null));
</script>

<template>
  <section id="discord">
    <h2 class="h5 mb-3">Discord</h2>
    <p class="small text-muted">
      Connect your Discord account to list your <strong>friends</strong> on the Connections page. Each one comes with a suggestion,
      found by matching your nickname for them, their display name and their username against your connections, and you decide: create
      a connection, add their Discord user ID to one you already have, or dismiss them. Discord friends who are already on a connection
      (by user ID) are skipped.
    </p>
    <p class="small text-muted">
      Once a friend is on a connection, their <strong>display name and username</strong> are also added to that connection's highlight tokens
      (when you've chosen a highlight tokens field under Share links), so events that use either name still highlight. After a rename the new
      name is added next to the old one, and nothing is ever removed. Your private nickname for them is not copied.
    </p>
    <p class="small text-muted">
      A connection with a Discord user ID but no known profile (the person isn't one of your friends, or nothing has been synced yet) shows a
      placeholder on its Discord card. Where the server has a Discord bot token set up, its <strong>Sync</strong> button asks Discord about that
      one ID directly and fills in the avatar, display name and username, and adds the names as highlight tokens just the same. This works for
      people who are not your friends, and needs no connected account. Those people are never added to the friends waiting to be linked.
    </p>
    <p class="small text-muted">
      This data is <strong>not</strong> end-to-end encrypted: the server asks Discord for your friend list (the <code>identify</code> and
      <code>relationships.read</code> permissions) and stores the access tokens and the friends it saw encrypted at rest with the app key.
      Your connections' names stay end-to-end encrypted, so your browser does the matching and creating. You can disconnect at any time,
      which also revokes the access at Discord.
    </p>

    <p v-if="!discord.available" class="small text-warning mb-0">Discord is not set up on this server, so it cannot be connected.</p>

    <VaultGate v-else compact>
      <form id="discord-user-id" class="mb-3" @submit.prevent="saveField">
        <p v-if="!definitions.length" class="small mb-0">
          No identifier or identifier list fields yet — add one under Connections → Custom attributes to hold Discord user IDs.
        </p>
        <template v-else>
          <BFormGroup
            label="Discord user ID field"
            description="Where a connection's Discord user ID is kept. It also receives the Discord IDs VRCX reports for VRChat friends, and it has to be a different field from the VRChat one."
            style="max-width: 28rem;"
          >
            <BFormSelect v-model="form.discord_user_id_definition_id" :options="fieldOptions" size="sm" />
            <div v-if="form.errors.discord_user_id_definition_id" class="text-danger small">{{ form.errors.discord_user_id_definition_id }}</div>
          </BFormGroup>
          <BButton type="submit" size="sm" variant="primary" class="mt-2" :disabled="form.processing || !form.isDirty">Save field</BButton>
        </template>
      </form>

      <div v-if="connectError" class="small text-danger mb-2" role="alert">{{ connectError }}</div>

      <div class="d-flex flex-wrap gap-2 align-items-center">
        <template v-if="discord.connected">
          <span class="small">Connected as <strong>{{ discord.account_name ?? 'your Discord account' }}</strong>.</span>
          <BButton size="sm" variant="outline-secondary" :disabled="syncing || !hasField || form.isDirty" :title="!hasField ? 'Choose and save the Discord user ID field first' : form.isDirty ? 'Save the field first' : undefined" @click="syncNow">
            {{ syncing ? 'Syncing…' : 'Sync now' }}
          </BButton>
          <BButton size="sm" variant="outline-secondary" href="/settings/discord/connect">Reconnect</BButton>
          <BButton size="sm" variant="outline-danger" @click="disconnect">Disconnect</BButton>
          <span v-if="syncedAt" class="small text-muted">Last synced {{ syncedAt }}</span>
        </template>
        <BButton v-else size="sm" variant="primary" href="/settings/discord/connect">Connect Discord</BButton>
      </div>
      <div v-if="syncResult" class="small mt-2" :class="syncResult.ok ? 'text-success' : 'text-danger'" role="status">{{ syncResult.text }}</div>
    </VaultGate>
  </section>
</template>
