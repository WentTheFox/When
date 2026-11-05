<script setup lang="ts">
import { faBoxArchive, faLink } from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import { BButton, BCard, BFormGroup, BFormInput, BFormSelect, BFormTextarea, BTab, BTabs } from 'bootstrap-vue-next';
import { computed, nextTick, onBeforeUnmount, onMounted, type Ref, ref, watch } from 'vue';
import { decryptString, encryptString } from '../../crypto';
import AttributesPanel from '../../dashboard/AttributesPanel.vue';
import CategoriesPanel from '../../dashboard/CategoriesPanel.vue';
import EncryptedText from '../../Components/EncryptedText.vue';
import ConnectionCard, { type ConnectionRow } from '../../dashboard/ConnectionCard.vue';
import SourcesPanel from '../../dashboard/SourcesPanel.vue';
import {
  buildConnectionsHash,
  hasRecord,
  PAGE_TABS,
  parseConnectionsHash,
  type PageTabName,
} from '../../dashboard/connectionsHash';
import LockedOverlay from '../../dashboard/LockedOverlay.vue';
import { alphabetical, type LabelOf, placeAlphabetically, sameOrder } from '../../dashboard/listOrder';
import { requestUnlock } from '../../dashboard/vaultModal';
import { useVault } from '../../dashboard/useVault';
import DashboardLayout from '../../Layouts/DashboardLayout.vue';

defineOptions({ layout: DashboardLayout });

interface SourceRow { id: string; category_id: string | null; name_ciphertext: string }
interface CategoryRow { id: string; color_key: string | null; name_ciphertext: string }
interface DefinitionRow { id: string; label_ciphertext: string; type: string; options_ciphertext: string | null }
interface EdgeRow { id: string; from_connection_id: string; to_connection_id: string }

const props = defineProps<{
  connections: ConnectionRow[];
  sources: SourceRow[];
  categories: CategoryRow[];
  attributeDefinitions: DefinitionRow[];
  edges: EdgeRow[];
  /** Fields that site features depend on (field id to feature labels); those can't be deleted. */
  fieldFeatures: Record<string, string[]>;
}>();

const { createRecordKey, getRecordKey, vaultUnlocked } = useVault();

const connections = ref<ConnectionRow[]>(props.connections);
/**
 * The lists below are populated from the server's data right away, with every
 * name still as ciphertext (`label` undefined) — they show the raw blobs under
 * the locked overlay and fill in as the vault is unlocked and each item is
 * decrypted. The decrypted-only views further down are what the forms and
 * connection cards use.
 */
interface SourceItem { id: string; category_id: string | null; name_ciphertext: string; label?: string }
interface CategoryItem { id: string; color_key: string | null; name_ciphertext: string; label?: string }
interface DefinitionItem { id: string; type: string; label_ciphertext: string; options_ciphertext: string | null; label?: string; options: string[] }

const sourceItems = ref<SourceItem[]>(props.sources.map((s) => ({ ...s })));
const categoryItems = ref<CategoryItem[]>(props.categories.map((c) => ({ ...c })));
const definitionItems = ref<DefinitionItem[]>(props.attributeDefinitions.map((d) => ({ ...d, options: [] })));

const sources = computed(() =>
  sourceItems.value
    .filter((s): s is SourceItem & { label: string } => s.label !== undefined)
    .map(({ id, category_id, label }) => ({ id, category_id, label })),
);
const categories = computed(() =>
  categoryItems.value
    .filter((c): c is CategoryItem & { label: string } => c.label !== undefined)
    .map(({ id, color_key, label }) => ({ id, color_key, label })),
);
const definitions = computed(() =>
  definitionItems.value
    .filter((d): d is DefinitionItem & { label: string } => d.label !== undefined)
    .map(({ id, label, type, options }) => ({ id, label, type, options })),
);
const edges = ref<EdgeRow[]>([]);
/** Why the last attempt to delete a field was refused, if it was. */
const definitionError = ref<string | null>(null);

/** How many connections hold a value for each custom attribute (definition id to count). */
const definitionUsage = computed(() => {
  const counts: Record<string, number> = {};
  for (const connection of connections.value) {
    for (const value of connection.attribute_values) {
      counts[value.attribute_definition_id] = (counts[value.attribute_definition_id] ?? 0) + 1;
    }
  }
  return counts;
});

const showNewForm = ref(false);
const newName = ref('');
const newSourceIds = ref<string[]>([]);
const newNotes = ref('');
const newConnectionError = ref('');

const decryptedNames = ref<Record<string, string>>({});
// Everything below keeps the lists' stored order (see listOrder.ts): names finishing their decryption must not re-sort anything.
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
const initialHash = typeof window === 'undefined' ? { tab: 'connections' as PageTabName } : parseConnectionsHash(window.location.hash);
/** An id from the hash only counts if that record exists, so a stale link just opens nothing. */
function known(list: { id: string }[], id?: string): string | null {
  return id && list.some((item) => item.id === id) ? id : null;
}
/** The id from the hash, if it names a record in the given tab. */
function initialIdFor(tab: PageTabName, list: { id: string }[]): string | null {
  return initialHash.tab === tab ? known(list, initialHash.id) : null;
}

/** Which of the page's tabs is showing (a PAGE_TABS id). */
const pageTab = ref<string>(PAGE_TABS[initialHash.tab]);
const selectedConnectionId = ref<string | null>(initialIdFor('connections', props.connections));
const selectedSourceId = ref<string | null>(initialIdFor('sources', props.sources));
const selectedCategoryId = ref<string | null>(initialIdFor('categories', props.categories));
const selectedDefinitionId = ref<string | null>(initialIdFor('attributes', props.attributeDefinitions));
const selectedConnection = computed(() => connections.value.find((c) => c.id === selectedConnectionId.value) ?? null);

const connectionFilter = ref('');
const filteredConnections = computed(() => {
  const query = connectionFilter.value.trim().toLowerCase();
  if (!query) return connections.value;
  return connections.value.filter((c) => (decryptedNames.value[c.id] ?? '').toLowerCase().includes(query));
});

type ListKind = 'connections' | 'sources' | 'categories' | 'attributes';

/** Saves a list's order (names are E2EE, so only this client can sort them). A failure just leaves the stored order as it was. */
async function persistOrder(kind: ListKind, items: readonly { id: string }[]): Promise<void> {
  try {
    await axios.post(`/dashboard/order/${kind}`, { ids: items.map((item) => item.id) });
  } catch (error) {
    console.error(error);
  }
}

/** Moves one new or renamed record to its alphabetical place and saves the order, if that moved anything. */
async function placeAndSave<T extends { id: string }>(list: Ref<T[]>, kind: ListKind, id: string, labelOf: LabelOf<T>): Promise<void> {
  const placed = placeAlphabetically(list.value, id, labelOf);
  if (sameOrder(placed, list.value)) return;

  list.value = placed;
  await persistOrder(kind, placed);
}

/**
 * Older data is stored in creation order. Once every name is known, sort each
 * list alphabetically in one go and save it — a single re-order, after which
 * the stored order is already alphabetical and later loads don't move at all.
 */
async function normalizeList<T extends { id: string }>(list: Ref<T[]>, kind: ListKind, labelOf: LabelOf<T>): Promise<void> {
  const target = alphabetical(list.value, labelOf);
  if (sameOrder(target, list.value)) return;

  list.value = target;
  await persistOrder(kind, target);
}

const labelOfConnection: LabelOf<ConnectionRow> = (c) => decryptedNames.value[c.id];
const labelOfItem: LabelOf<{ label?: string }> = (item) => item.label;

async function normalizeAllLists(): Promise<void> {
  await normalizeList(connections, 'connections', labelOfConnection);
  await normalizeList(sourceItems, 'sources', labelOfItem);
  await normalizeList(categoryItems, 'categories', labelOfItem);
  await normalizeList(definitionItems, 'attributes', labelOfItem);
}

watch(vaultUnlocked, async (unlocked) => {
  if (!unlocked) return;

  for (const source of sourceItems.value) {
    if (source.label !== undefined) continue;
    try {
      const key = await getRecordKey(source.id);
      source.label = await decryptString(key, source.name_ciphertext);
    } catch (error) {
      console.error(error);
    }
  }

  for (const category of categoryItems.value) {
    if (category.label !== undefined) continue;
    try {
      const key = await getRecordKey(category.id);
      category.label = await decryptString(key, category.name_ciphertext);
    } catch (error) {
      console.error(error);
    }
  }

  for (const definition of definitionItems.value) {
    if (definition.label !== undefined) continue;
    try {
      const key = await getRecordKey(definition.id);
      if (definition.options_ciphertext) {
        try {
          definition.options = JSON.parse(await decryptString(key, definition.options_ciphertext)).choices ?? [];
        } catch (error) {
          console.error(error);
        }
      }
      definition.label = await decryptString(key, definition.label_ciphertext);
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

  edges.value = props.edges.map((edge) => ({ ...edge }));

  await normalizeAllLists();
}, { immediate: true });

/**
 * The active tab and the record open in it live in the URL hash (see
 * connectionsHash.ts), so a reload — or a link — reopens the same view. It is
 * written with replaceState (keeping Inertia's history state), so selecting
 * things doesn't pile up history entries or jump the page.
 */
function activeTabName(): PageTabName {
  return (Object.keys(PAGE_TABS) as PageTabName[]).find((name) => PAGE_TABS[name] === pageTab.value) ?? 'connections';
}

/** The selection ref belonging to each tab. */
function selectionFor(tab: PageTabName) {
  return { connections: selectedConnectionId, sources: selectedSourceId, categories: selectedCategoryId, attributes: selectedDefinitionId }[tab];
}

function writeHash(): void {
  const tab = activeTabName();
  const hash = buildConnectionsHash({ tab, id: selectionFor(tab).value ?? undefined });

  if (window.location.hash === hash) return;

  window.history.replaceState(window.history.state, '', `${window.location.pathname}${window.location.search}${hash}`);
}

watch([pageTab, selectedConnectionId, selectedSourceId, selectedCategoryId, selectedDefinitionId], writeHash);
watch(selectedDefinitionId, () => { definitionError.value = null; });

/** Applies whatever the hash names (a hand-edited hash or an in-page link). */
function applyHash(): void {
  const state = parseConnectionsHash(window.location.hash);
  pageTab.value = PAGE_TABS[state.tab];

  if (!state.id) return;

  const lists = { connections: connections.value, sources: sourceItems.value, categories: categoryItems.value, attributes: definitionItems.value };
  selectionFor(state.tab).value = known(lists[state.tab], state.id);
}

/**
 * A hash that names a record is an explicit request to look at it, so it asks
 * for the unlock straight away — its form is already on screen, undecrypted,
 * under the locked overlay. A hash with only a tab (or none) prompts nothing;
 * the lists only ask when clicked.
 */
function openFromHash(): void {
  if (!hasRecord(parseConnectionsHash(window.location.hash))) return;

  void requestUnlock();

  nextTick(() => {
    document.getElementById('connections-workspace')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });
}

function onHashChange(): void {
  applyHash();
  openFromHash();
}

onMounted(() => {
  writeHash(); // normalizes an older #connection-<id> link to the current form
  window.addEventListener('hashchange', onHashChange);
  openFromHash();
});

onBeforeUnmount(() => window.removeEventListener('hashchange', onHashChange));

function toggleNewForm(): void {
  showNewForm.value = !showNewForm.value;
  if (showNewForm.value && !newName.value) newName.value = connectionFilter.value.trim();
}

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

    connections.value.push(data);
    decryptedNames.value[id] = newName.value;
    selectedConnectionId.value = id;
    void placeAndSave(connections, 'connections', id, labelOfConnection);

    newName.value = '';
    newSourceIds.value = [];
    newNotes.value = '';
    showNewForm.value = false;
    connectionFilter.value = '';
  } catch (error) {
    console.error(error);
    newConnectionError.value = 'Could not create that connection.';
  }
}

function onConnectionUpdated(updated: ConnectionRow): void {
  const index = connections.value.findIndex((c) => c.id === updated.id);
  if (index !== -1) connections.value[index] = updated;
}

/** A saved connection whose name changed: show the new name in the list and move it to its alphabetical place. */
function onConnectionRenamed(id: string, name: string): void {
  decryptedNames.value[id] = name;
  void placeAndSave(connections, 'connections', id, labelOfConnection);
}

function onConnectionDeleted(id: string): void {
  connections.value = connections.value.filter((c) => c.id !== id);
  if (selectedConnectionId.value === id) selectedConnectionId.value = null;
  void clearOrphanedReferences();
}

/**
 * 'connection'-type values hold the target's id inside E2EE ciphertext, so the
 * server can't foreign-key or cascade them. This is the client-side stand-in:
 * decrypt every such value, and for those pointing at a connection that no
 * longer exists, ask the server to drop that row. Runs after every delete and
 * on demand; idempotent. Rendering also tolerates orphans, so a failure here
 * only leaves a "(deleted connection)" label behind.
 */
async function clearOrphanedReferences(): Promise<number> {
  const liveIds = new Set(connections.value.map((c) => c.id));
  let cleared = 0;
  for (const definition of definitions.value.filter((d) => d.type === 'connection')) {
    const orphaned: string[] = [];
    for (const connection of connections.value) {
      const row = connection.attribute_values.find((v) => v.attribute_definition_id === definition.id);
      if (!row?.value_ciphertext) continue;
      try {
        const target = await decryptString(await getRecordKey(connection.id), row.value_ciphertext);
        if (!liveIds.has(target)) orphaned.push(connection.id);
      } catch (error) {
        console.error(error);
      }
    }
    for (let i = 0; i < orphaned.length; i += 500) {
      const chunk = orphaned.slice(i, i + 500);
      try {
        await axios.post(`/dashboard/connection-attribute-definitions/${definition.id}/clear-values`, { connection_ids: chunk });
        const gone = new Set(chunk);
        connections.value = connections.value.map((c) => gone.has(c.id)
          ? { ...c, attribute_values: c.attribute_values.filter((v) => v.attribute_definition_id !== definition.id) }
          : c);
        cleared += chunk.length;
      } catch (error) {
        console.error(error);
      }
    }
  }
  return cleared;
}

async function sweepOrphans(): Promise<void> {
  copyBusy.value = true;
  copyStatus.value = 'Checking references…';
  try {
    const cleared = await clearOrphanedReferences();
    copyStatus.value = `Removed ${cleared} reference${cleared === 1 ? '' : 's'} to deleted connections.`;
  } finally {
    copyBusy.value = false;
  }
}

async function addSource(name: string): Promise<void> {
  try {
    const id = crypto.randomUUID();
    const key = await createRecordKey(id);
    const nameCiphertext = await encryptString(key, name);
    await axios.post('/dashboard/connection-sources', { id, name_ciphertext: nameCiphertext });
    sourceItems.value.push({ id, category_id: null, name_ciphertext: nameCiphertext, label: name });
    await placeAndSave(sourceItems, 'sources', id, labelOfItem);
  } catch (error) {
    console.error(error);
  }
}

async function updateSource(id: string, name: string, categoryId: string | null): Promise<void> {
  try {
    const key = await getRecordKey(id);
    const nameCiphertext = await encryptString(key, name);
    await axios.patch(`/dashboard/connection-sources/${id}`, {
      name_ciphertext: nameCiphertext,
      category_id: categoryId,
    });
    const source = sourceItems.value.find((s) => s.id === id);
    if (source) {
      source.name_ciphertext = nameCiphertext;
      source.label = name;
      source.category_id = categoryId;
      await placeAndSave(sourceItems, 'sources', id, labelOfItem);
    }
  } catch (error) {
    console.error(error);
  }
}

async function removeSource(id: string): Promise<void> {
  try {
    await axios.delete(`/dashboard/connection-sources/${id}`);
    sourceItems.value = sourceItems.value.filter((s) => s.id !== id);
  } catch (error) {
    console.error(error);
  }
}

async function addCategory(name: string): Promise<void> {
  try {
    const id = crypto.randomUUID();
    const key = await createRecordKey(id);
    const nameCiphertext = await encryptString(key, name);
    await axios.post('/dashboard/connection-source-categories', { id, name_ciphertext: nameCiphertext });
    categoryItems.value.push({ id, color_key: null, name_ciphertext: nameCiphertext, label: name });
    await placeAndSave(categoryItems, 'categories', id, labelOfItem);
  } catch (error) {
    console.error(error);
  }
}

async function updateCategory(id: string, name: string, colorKey: string | null): Promise<void> {
  try {
    const key = await getRecordKey(id);
    const nameCiphertext = await encryptString(key, name);
    await axios.patch(`/dashboard/connection-source-categories/${id}`, {
      name_ciphertext: nameCiphertext,
      color_key: colorKey,
    });
    const category = categoryItems.value.find((c) => c.id === id);
    if (category) {
      category.name_ciphertext = nameCiphertext;
      category.label = name;
      category.color_key = colorKey;
      await placeAndSave(categoryItems, 'categories', id, labelOfItem);
    }
  } catch (error) {
    console.error(error);
  }
}

async function removeCategory(id: string): Promise<void> {
  try {
    await axios.delete(`/dashboard/connection-source-categories/${id}`);
    categoryItems.value = categoryItems.value.filter((c) => c.id !== id);
  } catch (error) {
    console.error(error);
  }
}

async function addDefinition(label: string, type: string, choices: string[]): Promise<void> {
  try {
    const id = crypto.randomUUID();
    const key = await createRecordKey(id);
    const optionsCiphertext = type === 'radio' ? await encryptString(key, JSON.stringify({ choices })) : null;
    const labelCiphertext = await encryptString(key, label);
    await axios.post('/dashboard/connection-attribute-definitions', {
      id,
      label_ciphertext: labelCiphertext,
      type,
      options_ciphertext: optionsCiphertext,
    });
    definitionItems.value.push({ id, type, label_ciphertext: labelCiphertext, options_ciphertext: optionsCiphertext, label, options: choices });
    await placeAndSave(definitionItems, 'attributes', id, labelOfItem);
  } catch (error) {
    console.error(error);
  }
}

const copyStatus = ref<string | null>(null);
const copyBusy = ref(false);

/**
 * Decrypts every connection's value for the source text definition locally,
 * then sends the plaintext to the fill-identifiers endpoint (identifiers are
 * §0.2-tier, not E2EE). Only connections whose identifier is currently empty
 * are sent, and the server re-checks that, so re-running is a no-op.
 */
async function copyToIdentifier(sourceId: string, targetId: string): Promise<void> {
  copyBusy.value = true;
  copyStatus.value = 'Decrypting…';
  try {
    const pending: { connection_id: string; identifier_value: string }[] = [];
    for (const connection of connections.value) {
      const existing = connection.attribute_values.find((v) => v.attribute_definition_id === targetId);
      if (existing?.identifier_value) continue;
      const source = connection.attribute_values.find((v) => v.attribute_definition_id === sourceId);
      if (!source?.value_ciphertext) continue;
      try {
        const value = (await decryptString(await getRecordKey(connection.id), source.value_ciphertext)).trim();
        if (value) pending.push({ connection_id: connection.id, identifier_value: value });
      } catch (error) {
        console.error(error);
      }
    }

    const filled = new Map(pending.map((p) => [p.connection_id, p.identifier_value]));
    const written = new Set<string>();
    for (let i = 0; i < pending.length; i += 500) {
      copyStatus.value = `Copying ${Math.min(i + 500, pending.length)} of ${pending.length}…`;
      const { data } = await axios.post(`/dashboard/connection-attribute-definitions/${targetId}/fill-identifiers`, {
        values: pending.slice(i, i + 500),
      });
      for (const id of data.filled as string[]) written.add(id);
    }

    connections.value = connections.value.map((c) => {
      const value = filled.get(c.id);
      if (!written.has(c.id) || value === undefined) return c;
      return {
        ...c,
        attribute_values: [
          ...c.attribute_values.filter((v) => v.attribute_definition_id !== targetId),
          { attribute_definition_id: targetId, value_ciphertext: null, identifier_value: value },
        ],
      };
    });
    copyStatus.value = `Copied ${written.size} value${written.size === 1 ? '' : 's'}; ${connections.value.length - written.size} skipped (already set or no source value).`;
  } catch (error) {
    console.error(error);
    copyStatus.value = 'Copy failed — nothing already written was changed; you can safely retry.';
  } finally {
    copyBusy.value = false;
  }
}

async function renameDefinition(id: string, label: string): Promise<void> {
  try {
    const key = await getRecordKey(id);
    const labelCiphertext = await encryptString(key, label);
    await axios.patch(`/dashboard/connection-attribute-definitions/${id}`, { label_ciphertext: labelCiphertext });
    const definition = definitionItems.value.find((d) => d.id === id);
    if (definition) {
      definition.label_ciphertext = labelCiphertext;
      definition.label = label;
      await placeAndSave(definitionItems, 'attributes', id, labelOfItem);
    }
  } catch (error) {
    console.error(error);
  }
}

/** The panel has already asked for confirmation. The server still refuses a field a site feature depends on. */
async function removeDefinition(id: string): Promise<void> {
  definitionError.value = null;

  try {
    await axios.delete(`/dashboard/connection-attribute-definitions/${id}`);
    definitionItems.value = definitionItems.value.filter((d) => d.id !== id);

    // The server deleted every connection's value for it.
    connections.value = connections.value.map((c) => ({
      ...c,
      attribute_values: c.attribute_values.filter((v) => v.attribute_definition_id !== id),
    }));
  } catch (error) {
    console.error(error);
    if (axios.isAxiosError(error) && error.response?.status === 422) {
      definitionError.value = error.response.data?.errors?.definition?.[0] ?? 'This field cannot be deleted right now.';
    }
  }
}

/** "Knows" is undirected: at most one edge per pair, whichever side it was added from. */
async function addEdge(fromId: string, toId: string): Promise<void> {
  const alreadyKnown = edges.value.some(
    (e) => (e.from_connection_id === fromId && e.to_connection_id === toId)
      || (e.from_connection_id === toId && e.to_connection_id === fromId),
  );
  if (alreadyKnown) return;

  try {
    const { data } = await axios.post<EdgeRow>('/dashboard/connection-edges', {
      from_connection_id: fromId,
      to_connection_id: toId,
    });

    // The server returns the existing edge if one raced in; don't list it twice.
    if (!edges.value.some((e) => e.id === data.id)) edges.value.push(data);
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
    </div>
  </BCard>

  <BCard id="connections-workspace" class="mb-4">
    <BTabs v-model="pageTab" pills no-fade content-class="pt-3">
      <BTab :id="PAGE_TABS.connections">
        <template #title>Connections <span class="text-muted">({{ connections.length }})</span></template>
        <div class="row">
          <div class="col-md-4">
            <LockedOverlay>
              <div class="input-group input-group-sm mb-2">
                <BFormInput v-model="connectionFilter" type="text" placeholder="Search or new connection name" />
                <BButton variant="outline-secondary" @click="toggleNewForm">New</BButton>
              </div>

              <BCard v-if="showNewForm" class="mb-2" body-class="p-2">
                <BFormGroup label="Name" label-class="small" class="mb-2">
                  <BFormInput v-model="newName" type="text" size="sm" />
                </BFormGroup>
                <BFormGroup label="Sources" label-class="small" description="Ctrl/Cmd-click to select more than one." class="mb-2">
                  <BFormSelect v-model="newSourceIds" size="sm" multiple>
                    <option v-for="source in sources" :key="source.id" :value="source.id">{{ source.label }}</option>
                  </BFormSelect>
                </BFormGroup>
                <BFormGroup label="Notes" label-class="small" class="mb-2">
                  <BFormTextarea v-model="newNotes" size="sm" rows="2" />
                </BFormGroup>
                <BButton size="sm" variant="primary" @click="createConnection">Create</BButton>
                <div class="text-danger small mt-2">{{ newConnectionError }}</div>
              </BCard>

              <p v-if="filteredConnections.length === 0" class="text-muted">No connections found.</p>
              <div v-else class="list-group wtf-master-list">
                <button
                  v-for="connection in filteredConnections"
                  :key="connection.id"
                  type="button"
                  class="list-group-item list-group-item-action"
                  :class="{ active: selectedConnectionId === connection.id }"
                  @click="selectedConnectionId = connection.id"
                >
                  <EncryptedText :value="decryptedNames[connection.id]" :ciphertext="connection.name_ciphertext" />
                  <!-- Has a share link set up. The selected row is blue itself, so there it just inherits the white text. -->
                  <span
                    v-if="connection.share_link"
                    class="ms-1 connection-shared"
                    :class="selectedConnectionId === connection.id ? '' : 'text-info'"
                    title="Has a share link"
                  >
                    <FontAwesomeIcon :icon="faLink" />
                    <span class="visually-hidden">Has a share link</span>
                  </span>
                  <!-- Archived. Muted, except on the selected row, where the inherited white stays readable. -->
                  <span
                    v-if="connection.archived"
                    class="ms-1 connection-archived"
                    :class="selectedConnectionId === connection.id ? '' : 'text-muted'"
                    title="Archived"
                  >
                    <FontAwesomeIcon :icon="faBoxArchive" />
                    <span class="visually-hidden">Archived</span>
                  </span>
                </button>
              </div>
            </LockedOverlay>
          </div>

          <div id="connection-detail-pane" class="col-md-8">
            <LockedOverlay v-if="selectedConnection">
              <ConnectionCard
                :key="selectedConnection.id"
                :connection="selectedConnection"
                :sources="sources"
                :attribute-definitions="definitions"
                :edges="edges"
                :connection-options="connectionOptions"
                @updated="onConnectionUpdated"
                @renamed="onConnectionRenamed"
                @deleted="onConnectionDeleted"
                @add-edge="addEdge"
                @remove-edge="removeEdge"
              />
            </LockedOverlay>
            <p v-else class="text-muted">Select a connection on the left to view or edit it.</p>
          </div>
        </div>
      </BTab>

      <BTab :id="PAGE_TABS.sources">
        <template #title>Sources <span class="text-muted">({{ sourceItems.length }})</span></template>
        <SourcesPanel v-model:selected-id="selectedSourceId" :sources="sourceItems" :categories="categories" @add="addSource" @update="updateSource" @remove="removeSource" />
      </BTab>

      <BTab :id="PAGE_TABS.categories">
        <template #title>Categories <span class="text-muted">({{ categoryItems.length }})</span></template>
        <CategoriesPanel v-model:selected-id="selectedCategoryId" :categories="categoryItems" @add="addCategory" @update="updateCategory" @remove="removeCategory" />
      </BTab>

      <BTab :id="PAGE_TABS.attributes">
        <template #title>Custom attributes <span class="text-muted">({{ definitionItems.length }})</span></template>
        <AttributesPanel
          v-model:selected-id="selectedDefinitionId"
          :definitions="definitionItems"
          :usage="definitionUsage"
          :field-features="fieldFeatures"
          :remove-error="definitionError"
          :copy-status="copyStatus"
          :copy-busy="copyBusy"
          @add="addDefinition"
          @rename="renameDefinition"
          @remove="removeDefinition"
          @copy-to-identifier="copyToIdentifier"
          @sweep-orphans="sweepOrphans"
        />
      </BTab>
    </BTabs>
  </BCard>
</template>
