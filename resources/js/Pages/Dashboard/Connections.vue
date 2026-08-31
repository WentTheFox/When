<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import { BButton, BCard, BFormGroup, BFormInput, BFormSelect, BFormTextarea } from 'bootstrap-vue-next';
import { computed, nextTick, ref, watch } from 'vue';
import { decryptString, encryptString } from '../../crypto';
import AttributesPanel from '../../dashboard/AttributesPanel.vue';
import ConnectionCard, { type ConnectionRow } from '../../dashboard/ConnectionCard.vue';
import EdgesPanel from '../../dashboard/EdgesPanel.vue';
import SourcesPanel from '../../dashboard/SourcesPanel.vue';
import VaultGate from '../../dashboard/VaultGate.vue';
import { useVault } from '../../dashboard/useVault';
import DashboardLayout from '../../Layouts/DashboardLayout.vue';

defineOptions({ layout: DashboardLayout });

interface SourceRow { id: string; category_id: string | null; name_ciphertext: string }
interface DefinitionRow { id: string; label_ciphertext: string; type: string; options_ciphertext: string | null }
interface EdgeRow { id: string; from_connection_id: string; to_connection_id: string; label_ciphertext: string | null }

const props = defineProps<{
  connections: ConnectionRow[];
  sources: SourceRow[];
  attributeDefinitions: DefinitionRow[];
  edges: EdgeRow[];
}>();

const { createRecordKey, getRecordKey, vaultUnlocked } = useVault();

const connections = ref<ConnectionRow[]>(props.connections);
const sources = ref<{ id: string; category_id: string | null; label: string }[]>([]);
const definitions = ref<{ id: string; label: string; type: string; options: string[] }[]>([]);
const edges = ref<{ id: string; from_connection_id: string; to_connection_id: string; label: string }[]>([]);

const showNewForm = ref(false);
const newName = ref('');
const newSourceIds = ref<string[]>([]);
const newNotes = ref('');
const newConnectionError = ref('');

const decryptedNames = ref<Record<string, string>>({});
const connectionOptions = computed(() =>
  connections.value.map((c) => ({ id: c.id, label: decryptedNames.value[c.id] ?? '' })),
);

/**
 * Master-detail instead of every connection rendering full-size, one after
 * another, down the page — a list that stayed readable at 5 connections
 * became an endless scroll at 200 (a real number, not hypothetical: see
 * the source-app import). The left list only ever needs a name to pick
 * from; ConnectionCard itself is unchanged and does the actual showing/
 * editing, just for one connection at a time now instead of all of them
 * simultaneously.
 */
const selectedConnectionId = ref<string | null>(null);
const selectedConnection = computed(() => connections.value.find((c) => c.id === selectedConnectionId.value) ?? null);

watch(vaultUnlocked, async (unlocked) => {
  if (!unlocked) return;

  for (const source of props.sources) {
    try {
      const key = await getRecordKey(source.id);
      sources.value.push({
        id: source.id,
        category_id: source.category_id,
        label: await decryptString(key, source.name_ciphertext),
      });
    } catch (error) {
      console.error(error);
    }
  }

  for (const definition of props.attributeDefinitions) {
    try {
      const key = await getRecordKey(definition.id);
      let options: string[] = [];
      if (definition.options_ciphertext) {
        try {
          options = JSON.parse(await decryptString(key, definition.options_ciphertext)).choices ?? [];
        } catch (error) {
          console.error(error);
        }
      }
      definitions.value.push({
        id: definition.id,
        label: await decryptString(key, definition.label_ciphertext),
        type: definition.type,
        options,
      });
    } catch (error) {
      console.error(error);
    }
  }

  for (const connection of connections.value) {
    try {
      const key = await getRecordKey(connection.id);
      decryptedNames.value[connection.id] = await decryptString(key, connection.name_ciphertext);
    } catch (error) {
      console.error(error);
    }
  }

  for (const edge of props.edges) {
    let label = '';
    if (edge.label_ciphertext) {
      try {
        const key = await getRecordKey(edge.id);
        label = await decryptString(key, edge.label_ciphertext);
      } catch (error) {
        console.error(error);
      }
    }
    edges.value.push({ ...edge, label });
  }
}, { immediate: true });

/**
 * QuickSearch.vue (the dashboard-wide search box) links here as
 * /dashboard/connections#connection-<id> rather than trying to select from
 * the header component itself — that raced Inertia's page swap, since
 * nextTick() there only waits for a Vue update already scheduled at the
 * moment it's called, not one queued moments later. Reacting to
 * vaultUnlocked from inside this page instead means the select+scroll only
 * ever runs once this component's own detail pane can actually render:
 * either immediately (vault was already unlocked when this page loaded) or
 * once VaultGate's own unlock prompt resolves (it wasn't). Selecting the
 * connection in the master-detail layout below replaces what used to be
 * "scroll to this one's card in a long list" — the detail pane itself is
 * what gets scrolled into view now.
 */
watch(vaultUnlocked, (unlocked) => {
  if (!unlocked || !window.location.hash.startsWith('#connection-')) return;

  selectedConnectionId.value = window.location.hash.slice('#connection-'.length);

  nextTick(() => {
    document.getElementById('connection-detail-pane')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });
}, { immediate: true });

function nameOf(connectionId: string): string {
  return decryptedNames.value[connectionId] ?? '?';
}

const edgesForPanel = computed(() =>
  edges.value.map((edge) => ({
    id: edge.id,
    label: edge.label,
    fromLabel: nameOf(edge.from_connection_id),
    toLabel: nameOf(edge.to_connection_id),
  })),
);

async function createConnection(): Promise<void> {
  newConnectionError.value = '';

  if (!newName.value) {
    newConnectionError.value = 'Name is required.';
    return;
  }

  try {
    const id = crypto.randomUUID();
    const key = await createRecordKey(id);

    const { data } = await axios.post('/dashboard/connections', {
      id,
      source_ids: newSourceIds.value,
      name_ciphertext: await encryptString(key, newName.value),
      notes_ciphertext: newNotes.value ? await encryptString(key, newNotes.value) : null,
    });

    connections.value.unshift(data);
    decryptedNames.value[id] = newName.value;
    selectedConnectionId.value = id;

    newName.value = '';
    newSourceIds.value = [];
    newNotes.value = '';
    showNewForm.value = false;
  } catch (error) {
    console.error(error);
    newConnectionError.value = 'Could not create that connection.';
  }
}

function onConnectionUpdated(updated: ConnectionRow): void {
  const index = connections.value.findIndex((c) => c.id === updated.id);
  if (index !== -1) connections.value[index] = updated;
}

function onConnectionDeleted(id: string): void {
  connections.value = connections.value.filter((c) => c.id !== id);
  if (selectedConnectionId.value === id) selectedConnectionId.value = null;
}

async function addSource(name: string): Promise<void> {
  try {
    const id = crypto.randomUUID();
    const key = await createRecordKey(id);
    await axios.post('/dashboard/connection-sources', {
      id,
      name_ciphertext: await encryptString(key, name),
    });
    sources.value.push({ id, category_id: null, label: name });
  } catch (error) {
    console.error(error);
  }
}

async function updateSource(id: string, name: string): Promise<void> {
  try {
    const key = await getRecordKey(id);
    await axios.patch(`/dashboard/connection-sources/${id}`, {
      name_ciphertext: await encryptString(key, name),
    });
    const source = sources.value.find((s) => s.id === id);
    if (source) source.label = name;
  } catch (error) {
    console.error(error);
  }
}

async function removeSource(id: string): Promise<void> {
  try {
    await axios.delete(`/dashboard/connection-sources/${id}`);
    sources.value = sources.value.filter((s) => s.id !== id);
  } catch (error) {
    console.error(error);
  }
}

async function addDefinition(label: string, type: string, choices: string[]): Promise<void> {
  try {
    const id = crypto.randomUUID();
    const key = await createRecordKey(id);
    const optionsCiphertext = type === 'radio' ? await encryptString(key, JSON.stringify({ choices })) : null;
    await axios.post('/dashboard/connection-attribute-definitions', {
      id,
      label_ciphertext: await encryptString(key, label),
      type,
      options_ciphertext: optionsCiphertext,
    });
    definitions.value.push({ id, label, type, options: choices });
  } catch (error) {
    console.error(error);
  }
}

async function removeDefinition(id: string): Promise<void> {
  try {
    await axios.delete(`/dashboard/connection-attribute-definitions/${id}`);
    definitions.value = definitions.value.filter((d) => d.id !== id);
  } catch (error) {
    console.error(error);
  }
}

async function addEdge(fromId: string, toId: string, label: string): Promise<void> {
  try {
    const id = crypto.randomUUID();
    let labelCiphertext: string | null = null;
    if (label) {
      const key = await createRecordKey(id);
      labelCiphertext = await encryptString(key, label);
    }

    await axios.post('/dashboard/connection-edges', {
      id,
      from_connection_id: fromId,
      to_connection_id: toId,
      label_ciphertext: labelCiphertext,
    });

    edges.value.push({ id, from_connection_id: fromId, to_connection_id: toId, label });
  } catch (error) {
    console.error(error);
  }
}

async function removeEdge(id: string): Promise<void> {
  try {
    await axios.delete(`/dashboard/connection-edges/${id}`);
    edges.value = edges.value.filter((e) => e.id !== id);
  } catch (error) {
    console.error(error);
  }
}
</script>

<template>
  <Head title="Connections" />

  <BCard class="mb-4">
    <div class="d-flex justify-content-between align-items-center">
      <div>
        <h1 class="h3 mb-1">Connections</h1>
        <span class="text-muted small">Your private CRM, end-to-end encrypted.</span>
      </div>
      <BButton variant="primary" size="sm" @click="showNewForm = !showNewForm">New connection</BButton>
    </div>
  </BCard>

  <VaultGate>
    <BCard v-if="showNewForm" class="mb-4">
      <h2 class="h5 mb-3">New connection</h2>
      <div class="row">
        <div class="col-md-6">
          <BFormGroup label="Name" class="mb-3">
            <BFormInput v-model="newName" type="text" />
          </BFormGroup>
        </div>
        <div class="col-md-6">
          <BFormGroup label="Sources" description="Ctrl/Cmd-click to select more than one." class="mb-3">
            <BFormSelect v-model="newSourceIds" multiple>
              <option v-for="source in sources" :key="source.id" :value="source.id">{{ source.label }}</option>
            </BFormSelect>
          </BFormGroup>
        </div>
      </div>
      <BFormGroup label="Notes" class="mb-3">
        <BFormTextarea v-model="newNotes" rows="2" />
      </BFormGroup>
      <BButton variant="primary" @click="createConnection">Create</BButton>
      <div class="text-danger small mt-2">{{ newConnectionError }}</div>
    </BCard>

    <BCard class="mb-4">
      <div class="row">
        <div class="col-md-4">
          <p v-if="connections.length === 0" class="text-muted">No connections yet.</p>
          <div v-else class="list-group wtf-master-list">
            <button
              v-for="connection in connections"
              :key="connection.id"
              type="button"
              class="list-group-item list-group-item-action"
              :class="{ active: selectedConnectionId === connection.id }"
              @click="selectedConnectionId = connection.id"
            >
              {{ decryptedNames[connection.id] ?? '…' }}
              <span v-if="connection.archived" class="small text-muted">(archived)</span>
            </button>
          </div>
        </div>

        <div id="connection-detail-pane" class="col-md-8">
          <ConnectionCard
            v-if="selectedConnection"
            :key="selectedConnection.id"
            :connection="selectedConnection"
            :sources="sources"
            :attribute-definitions="definitions"
            @updated="onConnectionUpdated"
            @deleted="onConnectionDeleted"
          />
          <p v-else class="text-muted">Select a connection on the left to view or edit it.</p>
        </div>
      </div>
    </BCard>

    <SourcesPanel :sources="sources" @add="addSource" @update="updateSource" @remove="removeSource" />

    <div class="row">
      <div class="col-md-6">
        <AttributesPanel :definitions="definitions" @add="addDefinition" @remove="removeDefinition" />
      </div>
      <div class="col-md-6">
        <EdgesPanel :edges="edgesForPanel" :connections="connectionOptions" @add="addEdge" @remove="removeEdge" />
      </div>
    </div>
  </VaultGate>
</template>
