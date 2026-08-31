<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import { BAlert, BButton, BCard, BFormGroup, BFormInput } from 'bootstrap-vue-next';
import { computed, ref, watch } from 'vue';
import { decryptString, encryptString } from '../../crypto';
import ShareLinkCard, { type ShareLinkRow } from '../../dashboard/ShareLinkCard.vue';
import VaultGate from '../../dashboard/VaultGate.vue';
import { useVault } from '../../dashboard/useVault';
import DashboardLayout from '../../Layouts/DashboardLayout.vue';

defineOptions({ layout: DashboardLayout });

const props = defineProps<{
  shareLinks: ShareLinkRow[];
  connections: { id: string; name_ciphertext: string }[];
}>();
const { createRecordKey, getRecordKey, vaultUnlocked } = useVault();

const links = ref<ShareLinkRow[]>(props.shareLinks);
const showNewForm = ref(false);
const newLabel = ref('');
const newLinkError = ref('');
const createdUrl = ref('');
const creating = ref(false);
const selectedLinkId = ref<string | null>(null);
const selectedLink = computed(() => links.value.find((l) => l.id === selectedLinkId.value) ?? null);
const sortedLinks = computed(() =>
  [...links.value].sort((a, b) => (decryptedLabels.value[a.id] ?? '').localeCompare(decryptedLabels.value[b.id] ?? '')),
);

// Master-list display labels only — ShareLinkCard.vue does its own,
// independent decryption for the detail pane; this is a lighter-weight
// copy just so the left-hand list has something readable to show without
// waiting on whichever card happens to be selected.
const decryptedLabels = ref<Record<string, string>>({});
const decryptedConnectionNames = ref<Record<string, string>>({});

async function decryptListLabels(): Promise<void> {
  for (const connection of props.connections) {
    if (decryptedConnectionNames.value[connection.id]) continue;
    try {
      const key = await getRecordKey(connection.id);
      decryptedConnectionNames.value[connection.id] = await decryptString(key, connection.name_ciphertext);
    } catch (error) {
      console.error(error);
    }
  }

  for (const link of links.value) {
    if (decryptedLabels.value[link.id]) continue;
    if (!link.label_ciphertext) {
      decryptedLabels.value[link.id] = link.connection_id
        ? (decryptedConnectionNames.value[link.connection_id] ?? '…')
        : '(no label)';
      continue;
    }
    try {
      const key = await getRecordKey(link.id);
      decryptedLabels.value[link.id] = await decryptString(key, link.label_ciphertext);
    } catch (error) {
      console.error(error);
      decryptedLabels.value[link.id] = '(could not decrypt)';
    }
  }
}

watch([vaultUnlocked, links], ([unlocked]) => {
  if (unlocked) decryptListLabels();
}, { immediate: true, deep: true });

function select(id: string): void {
  selectedLinkId.value = id;
}

async function createLink(): Promise<void> {
  newLinkError.value = '';
  creating.value = true;

  try {
    const id = crypto.randomUUID();

    let labelCiphertext: string | null = null;
    if (newLabel.value) {
      const labelKey = await createRecordKey(id);
      labelCiphertext = await encryptString(labelKey, newLabel.value);
    }

    const { data } = await axios.post('/dashboard/share-links', { id, label_ciphertext: labelCiphertext });
    links.value.unshift(data);
    selectedLinkId.value = data.id;

    createdUrl.value = `${window.location.origin}/free/${id}`;

    newLabel.value = '';
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
  delete decryptedLabels.value[updated.id];
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
      <BButton variant="primary" :disabled="creating" @click="createLink">Create</BButton>
      <div class="text-danger small mt-2">{{ newLinkError }}</div>
    </BCard>

    <BCard>
      <div class="row">
        <div class="col-md-4">
          <p v-if="links.length === 0" class="text-muted small">No share links yet.</p>
          <div v-else class="list-group wtf-master-list">
            <button
              v-for="link in sortedLinks"
              :key="link.id"
              type="button"
              class="list-group-item list-group-item-action"
              :class="{ active: selectedLinkId === link.id }"
              @click="select(link.id)"
            >
              {{ decryptedLabels[link.id] ?? '…' }}
              <span v-if="link.archived" class="text-muted small"> (archived)</span>
            </button>
          </div>
        </div>

        <div class="col-md-8">
          <ShareLinkCard
            v-if="selectedLink"
            :key="selectedLink.id"
            :link="selectedLink"
            :connections="connections"
            @updated="onUpdated"
          />
          <p v-else class="text-muted">Select a share link on the left to view or edit it.</p>
        </div>
      </div>
    </BCard>
  </VaultGate>
</template>
