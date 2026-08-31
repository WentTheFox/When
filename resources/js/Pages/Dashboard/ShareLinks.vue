<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import { BAlert, BButton, BCard, BFormCheckbox, BFormGroup, BFormInput } from 'bootstrap-vue-next';
import { ref } from 'vue';
import {
  bytesToBase64,
  buildFragment,
  encryptString,
  exportAesKey,
  generateFragmentKey,
  wrapKeyWithPassphrase,
} from '../../crypto';
import ShareLinkCard, { type ShareLinkRow } from '../../dashboard/ShareLinkCard.vue';
import VaultGate from '../../dashboard/VaultGate.vue';
import { useVault } from '../../dashboard/useVault';
import DashboardLayout from '../../Layouts/DashboardLayout.vue';

defineOptions({ layout: DashboardLayout });

const props = defineProps<{
  shareLinks: ShareLinkRow[];
  connections: { id: string; name_ciphertext: string }[];
}>();
const { createRecordKey } = useVault();

const links = ref<ShareLinkRow[]>(props.shareLinks);
const showNewForm = ref(false);
const newLabel = ref('');
const usePassphrase = ref(false);
const newPassphrase = ref('');
const newLinkError = ref('');
const createdUrl = ref('');
const creating = ref(false);

async function createLink(): Promise<void> {
  newLinkError.value = '';

  if (usePassphrase.value && !newPassphrase.value) {
    newLinkError.value = 'Enter a passphrase, or uncheck passphrase protection.';
    return;
  }

  creating.value = true;

  try {
    const id = crypto.randomUUID();
    const { key, encoded } = await generateFragmentKey();
    const rawKey = await exportAesKey(key);

    let labelCiphertext: string | null = null;
    if (newLabel.value) {
      const labelKey = await createRecordKey(id);
      labelCiphertext = await encryptString(labelKey, newLabel.value);
    }

    const payload: Record<string, unknown> = {
      id,
      label_ciphertext: labelCiphertext,
      content_key: bytesToBase64(rawKey),
      key_protection: usePassphrase.value ? 'passphrase' : 'fragment',
    };

    if (usePassphrase.value) {
      const { wrappedKey, salt } = await wrapKeyWithPassphrase(key, newPassphrase.value);
      payload.wrapped_key = wrappedKey;
      payload.wrap_salt = salt;
    }

    const { data } = await axios.post('/dashboard/share-links', payload);
    links.value.unshift(data);

    createdUrl.value = usePassphrase.value
      ? `${window.location.origin}/free/${id}`
      : `${window.location.origin}/free/${id}#${buildFragment(encoded)}`;

    newLabel.value = '';
    newPassphrase.value = '';
    usePassphrase.value = false;
    showNewForm.value = false;
  } catch (error) {
    console.error(error);
    newLinkError.value = 'Could not create that link.';
  } finally {
    creating.value = false;
  }
}

function onUpdated(updated: ShareLinkRow): void {
  const index = links.value.findIndex((l) => l.id === updated.id);
  if (index !== -1) links.value[index] = updated;
}

function onRegenerated(updated: ShareLinkRow, url: string): void {
  onUpdated(updated);
  createdUrl.value = url;
}

async function exportLinks(): Promise<void> {
  const { data } = await axios.get('/dashboard/share-links/export');
  const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
  const url = URL.createObjectURL(blob);
  window.open(url, '_blank');
  setTimeout(() => URL.revokeObjectURL(url), 10000);
}

async function importLinks(event: Event): Promise<void> {
  const file = (event.target as HTMLInputElement).files?.[0];
  if (!file) return;

  try {
    const text = await file.text();
    await axios.post('/dashboard/share-links/import', JSON.parse(text));
    window.location.reload();
  } catch (error) {
    console.error(error);
    window.alert('Import failed. Check the file and try again.');
  }
}
</script>

<template>
  <Head title="Share links" />

  <BCard class="mb-4">
    <div class="d-flex justify-content-between align-items-center">
      <div>
        <h1 class="h3 mb-1">Share links</h1>
        <span class="text-muted small">Create, export, or import your share links.</span>
      </div>
      <div>
        <BButton variant="outline-secondary" size="sm" @click="exportLinks">Export</BButton>
        <label class="btn btn-outline-secondary btn-sm mb-0">
          Import
          <input type="file" accept="application/json" hidden @change="importLinks">
        </label>
        <BButton variant="primary" size="sm" @click="showNewForm = !showNewForm">New link</BButton>
      </div>
    </div>
  </BCard>

  <BAlert :model-value="!!createdUrl" variant="success" dismissible @close="createdUrl = ''">
    <strong>Link ready:</strong> <span class="font-monospace">{{ createdUrl }}</span>
  </BAlert>

  <VaultGate>
    <BCard v-if="showNewForm" class="mb-4">
      <h2 class="h5 mb-3">New share link</h2>
      <BFormGroup label="Label (private, only you can see it)" class="mb-3">
        <BFormInput v-model="newLabel" type="text" placeholder="For Mom" />
      </BFormGroup>
      <BFormCheckbox id="new-link-passphrase-toggle" v-model="usePassphrase" class="mb-3">
        Protect with a passphrase instead of a link fragment
      </BFormCheckbox>
      <BFormGroup v-if="usePassphrase" label="Passphrase" class="mb-3">
        <BFormInput v-model="newPassphrase" type="text" />
      </BFormGroup>
      <BButton variant="primary" :disabled="creating" @click="createLink">Create</BButton>
      <div class="text-danger small mt-2">{{ newLinkError }}</div>
    </BCard>

    <p v-if="links.length === 0" class="text-muted">No share links yet.</p>
    <ShareLinkCard
      v-for="link in links"
      :key="link.id"
      :link="link"
      :connections="connections"
      @updated="onUpdated"
      @regenerated="onRegenerated"
    />
  </VaultGate>
</template>
