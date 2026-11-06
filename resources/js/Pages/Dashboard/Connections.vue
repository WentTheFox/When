<script setup lang="ts">
import { faBoxArchive, faLink, faUserPlus } from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import { BAlert, BBadge, BButton, BCard, BFormGroup, BFormInput, BFormSelect, BFormTextarea, BTab, BTabs } from 'bootstrap-vue-next';
import { computed, nextTick, onBeforeUnmount, onMounted, type Ref, ref, watch } from 'vue';
import { decryptString, encryptString } from '../../crypto';
import PageHeader from '../../Components/PageHeader.vue';
import AttributesPanel from '../../dashboard/AttributesPanel.vue';
import CategoriesPanel from '../../dashboard/CategoriesPanel.vue';
import EncryptedText from '../../Components/EncryptedText.vue';
import ConnectionCard, { type ConnectionRow } from '../../dashboard/ConnectionCard.vue';
import SourcesPanel from '../../dashboard/SourcesPanel.vue';
import FriendReviewModal from '../../dashboard/FriendReviewModal.vue';
import MergeConnectionModal from '../../dashboard/MergeConnectionModal.vue';
import { holdsOtherId, type MatchCandidate, matchFriend, needsListConversion, type ReviewChoice, type ReviewItem } from '../../dashboard/friendMatching';
import type { PendingFriend, VrchatSummary } from '../../dashboard/vrchat';
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
  /** Cached VRChat names, pictures and time together (§0.2 tier, not E2EE). */
  vrchat: VrchatSummary;
}>();

const { createRecordKey, getRecordKey, vaultUnlocked } = useVault();

const connections = ref<ConnectionRow[]>(props.connections);
const vrchat = ref<VrchatSummary>(props.vrchat);
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
/**
 * Connections made from VRChat friends during this visit, newest first. They are
 * marked in the list and shown above the rest, so a pile of new people is easy to
 * find and review. Display only: the stored order stays alphabetical, so after a
 * reload they are back in their place and unmarked.
 */
const newConnectionIds = ref<string[]>([]);

const filteredConnections = computed(() => {
  const query = connectionFilter.value.trim().toLowerCase();
  const matching = query
    ? connections.value.filter((c) => (decryptedNames.value[c.id] ?? '').toLowerCase().includes(query))
    : connections.value;

  if (newConnectionIds.value.length === 0) return matching;

  const rank = (id: string) => newConnectionIds.value.indexOf(id);
  const fresh = matching.filter((c) => rank(c.id) !== -1).sort((a, b) => rank(b.id) - rank(a.id));

  return [...fresh, ...matching.filter((c) => rank(c.id) === -1)];
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
    applyVrchatProfiles(data);
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

const friendNotice = ref('');
let creatingFriends = false;

/** Friends that need the owner's decision (see FriendReviewModal), and what the claim that delivered them said about the source. */
const reviewItems = ref<ReviewItem[]>([]);
const reviewOpen = ref(false);
const reviewBusy = ref(false);
let reviewSourceId: string | null = null;
/** Pending friends this page load has already been handed, so a re-run doesn't fetch them again (a refresh starts empty and gets every unhandled one). */
const seenFriendIds: string[] = [];

/** The connection's attribute values as the update endpoint wants them back: only the column each one actually uses. */
function valuePayload(v: ConnectionRow['attribute_values'][number]): Record<string, unknown> {
  const payload: Record<string, unknown> = { attribute_definition_id: v.attribute_definition_id };

  for (const column of ['value_ciphertext', 'identifier_value', 'identifier_list_value', 'boolean_value'] as const) {
    if (v[column] !== null && v[column] !== undefined) payload[column] = v[column];
  }

  // Sent back unchanged, so the server keeps anything it added to the list since this page loaded.
  if (v.identifier_list_value) payload.identifier_list_base = v.identifier_list_value;

  return payload;
}

/**
 * Adds a friend's VRChat id to an existing connection's VRChat user ID field.
 * 'conflict' when a single-identifier field already holds a different id (it can
 * only be one person): the caller converts the field to a list first, or asks.
 */
async function linkFriend(
  connection: ConnectionRow,
  vrchatId: string,
  field: { id: string; type: string },
): Promise<'linked' | 'conflict'> {
  const existing = connection.attribute_values.find((v) => v.attribute_definition_id === field.id);
  let next: Record<string, unknown>;

  if (field.type === 'identifier_list') {
    const list = existing?.identifier_list_value ?? [];
    if (list.some((entry) => entry.trim().toLowerCase() === vrchatId)) return 'linked';
    next = { attribute_definition_id: field.id, identifier_list_value: [...list, vrchatId], identifier_list_base: list };
  } else {
    if (holdsOtherId(existing?.identifier_value, vrchatId)) return 'conflict';
    if ((existing?.identifier_value ?? '').trim() !== '') return 'linked';
    next = { attribute_definition_id: field.id, identifier_value: vrchatId };
  }

  // The update replaces every value, so send the others back unchanged.
  const values = [...connection.attribute_values.filter((v) => v.attribute_definition_id !== field.id).map(valuePayload), next];
  const { data } = await axios.patch<ConnectionRow>(`/dashboard/connections/${connection.id}`, { attribute_values: values });

  const index = connections.value.findIndex((c) => c.id === connection.id);
  if (index !== -1) connections.value[index] = data;

  return 'linked';
}

/** Makes a connection for a friend: named after them, the VRChat id in the field, the Settings source tied to it. */
async function createFriendConnection(friend: PendingFriend, field: { id: string; type: string }, sourceId: string | null): Promise<string> {
  const id = crypto.randomUUID();
  const key = await createRecordKey(id);
  const name = friend.display_name || friend.vrchat_user_id;
  const { data: row } = await axios.post('/dashboard/connections', {
    id,
    name_ciphertext: await encryptString(key, name),
    // The source chosen in Settings (the server omits it once that source is gone).
    source_ids: sourceId ? [sourceId] : [],
    attribute_values: [field.type === 'identifier_list'
      ? { attribute_definition_id: field.id, identifier_list_value: [friend.vrchat_user_id] }
      : { attribute_definition_id: field.id, identifier_value: friend.vrchat_user_id }],
  });

  connections.value.push(row);
  applyVrchatProfiles(row);
  decryptedNames.value[id] = name;
  void placeAndSave(connections, 'connections', id, labelOfConnection);
  newConnectionIds.value.push(id);

  return id;
}

/** Every connection's decrypted name and highlight tokens: what a friend is matched against (see friendMatching.ts). */
async function matchCandidates(): Promise<MatchCandidate[]> {
  const highlightFieldId = vrchat.value.highlight_field_id;
  const candidates: MatchCandidate[] = [];

  for (const connection of connections.value) {
    let name = decryptedNames.value[connection.id];

    if (name === undefined) {
      try {
        name = await decryptString(await getRecordKey(connection.id), connection.name_ciphertext);
        decryptedNames.value[connection.id] = name;
      } catch (error) {
        console.error(error);
        name = '';
      }
    }

    const tokens = highlightFieldId
      ? (connection.attribute_values.find((v) => v.attribute_definition_id === highlightFieldId)?.identifier_list_value ?? [])
      : [];

    candidates.push({ id: connection.id, name, tokens });
  }

  return candidates;
}

function showFriendNotice(created: number, linked: number, skipped: string[], review: number): void {
  const parts: string[] = [];
  if (created > 0) parts.push(`added ${created} connection${created === 1 ? '' : 's'}`);
  if (linked > 0) parts.push(`added ${linked} friend${linked === 1 ? '' : 's'} to existing connections`);

  const sentences: string[] = [];
  if (parts.length > 0) sentences.push(`VRChat friends: ${parts.join(', ')}.`);
  if (skipped.length > 0) sentences.push(`Skipped: ${skipped.slice(0, 10).join(', ')}${skipped.length > 10 ? `, and ${skipped.length - 10} more` : ''}.`);
  if (review > 0) sentences.push(`${review} friend${review === 1 ? ' is' : 's are'} waiting for your decision.`);

  if (sentences.length > 0) friendNotice.value = sentences.join(' ');
}

/**
 * VRChat friends without a connection (found by the server's sync) are dealt
 * with here, because a connection's name is encrypted with a key only this
 * unlocked vault has. Each friend is matched against the connections you
 * already have, by name or by highlight token (friendMatching.ts):
 *   - no match: a new connection, named after them, tied to the source chosen in Settings;
 *   - one match that can take the id: the id is added to that connection's VRChat field;
 *   - several matches, or one that already holds a different id in a single-identifier
 *     field: nothing happens yet, the owner decides in FriendReviewModal.
 * The server hands each friend to one browser at a time (a claim); a friend whose
 * request fails is simply offered again later.
 */
async function createFriendConnections(): Promise<void> {
  const field = vrchat.value.field;
  if (creatingFriends || !vaultUnlocked.value || !vrchat.value.auto_create_friends || !field || vrchat.value.pending_friends === 0) return;

  creatingFriends = true;
  let created = 0;
  let linked = 0;
  const undecided: ReviewItem[] = [];

  try {
    const candidates = await matchCandidates();

    for (;;) {
      const { data } = await axios.post<{ friends: PendingFriend[]; source_id: string | null }>('/dashboard/vrchat/friends/claim', {
        exclude: seenFriendIds.slice(-1000),
      });
      if (!data.friends.length) break;
      reviewSourceId = data.source_id;
      seenFriendIds.push(...data.friends.map((f) => f.id));

      const done: string[] = [];
      let handled = 0;

      for (const friend of data.friends) {
        const name = friend.display_name || friend.vrchat_user_id;

        try {
          const match = matchFriend(friend.display_name, candidates);
          const review = (ids: string[]) => {
            undecided.push({
              friendId: friend.id,
              vrchatId: friend.vrchat_user_id,
              name,
              candidates: ids.map((id) => {
                const connection = connections.value.find((c) => c.id === id);
                const existing = connection?.attribute_values.find((v) => v.attribute_definition_id === field.id);
                return {
                  id,
                  name: candidates.find((c) => c.id === id)?.name ?? '',
                  conflict: field.type === 'identifier' && holdsOtherId(existing?.identifier_value, friend.vrchat_user_id),
                };
              }),
            });
            handled++;
          };

          if (match.kind === 'several') {
            review(match.connectionIds);
          } else if (match.kind === 'one') {
            const connection = connections.value.find((c) => c.id === match.connectionId);

            if (connection && (await linkFriend(connection, friend.vrchat_user_id, field)) === 'linked') {
              done.push(friend.id);
              linked++;
              handled++;
            } else {
              review([match.connectionId]);
            }
          } else {
            const id = await createFriendConnection(friend, field, data.source_id);
            // A later friend with the same name must find this one, not make another.
            candidates.push({ id, name, tokens: [] });
            done.push(friend.id);
            created++;
            handled++;
          }
        } catch (error) {
          console.error(error);
        }
      }

      // Whatever wasn't finished (awaiting a decision, or failed) goes back to being offered: on the next page load, until it is handled.
      const released = data.friends.map((f) => f.id).filter((id) => !done.includes(id));
      await axios.post('/dashboard/vrchat/friends/complete', { ids: done, skipped: [], released });

      // Anything that failed is offered again by a later claim; don't spin on it now.
      if (handled < data.friends.length) break;
    }
  } catch (error) {
    console.error(error);
  } finally {
    creatingFriends = false;
  }

  if (undecided.length > 0) {
    reviewItems.value = [...reviewItems.value, ...undecided];
    reviewOpen.value = true;
  }

  showFriendNotice(created, linked, [], reviewItems.value.length);

  try {
    vrchat.value = (await axios.get<VrchatSummary>('/dashboard/vrchat')).data;
  } catch (error) {
    console.error(error);
  }
}

/** The VRChat ids a connection holds in the VRChat user ID field. */
function vrchatIdsOf(connection: ConnectionRow): string[] {
  const field = vrchat.value.field;
  if (!field) return [];

  const value = connection.attribute_values.find((v) => v.attribute_definition_id === field.id);
  const raw = [...(value?.identifier_list_value ?? []), value?.identifier_value ?? ''];

  return [...new Set(raw.map((id) => id.trim().toLowerCase()).filter((id) => /^usr_[0-9a-f-]+$/.test(id)))];
}

const mergeSourceId = ref<string | null>(null);
const mergeOpen = ref(false);
const mergeBusy = ref(false);
const mergeError = ref('');
const mergeSource = computed(() => connections.value.find((c) => c.id === mergeSourceId.value) ?? null);

function openMerge(sourceId: string): void {
  mergeSourceId.value = sourceId;
  mergeError.value = '';
  mergeOpen.value = true;
}

/**
 * "This connection is the same person as that one": adds this connection's VRChat
 * ids to the chosen connection (turning a single-identifier field into a list first
 * if that connection already holds a different id), then deletes this one. Nothing
 * is deleted unless every id was added.
 */
async function mergeConnectionInto(targetId: string): Promise<void> {
  const sourceId = mergeSourceId.value;
  let field = vrchat.value.field;
  if (mergeBusy.value || !sourceId || !field || !mergeSource.value) return;

  const ids = vrchatIdsOf(mergeSource.value);
  if (ids.length === 0) {
    mergeError.value = 'This connection has no VRChat ID to move.';
    return;
  }

  mergeBusy.value = true;
  mergeError.value = '';

  try {
    const targetValue = connections.value.find((c) => c.id === targetId)?.attribute_values.find((v) => v.attribute_definition_id === field!.id)?.identifier_value;

    if (field.type === 'identifier' && ids.some((id) => holdsOtherId(targetValue, id))) {
      await convertDefinitionToList(field.id);

      if (definitionItems.value.find((d) => d.id === field!.id)?.type !== 'identifier_list') {
        mergeError.value = definitionError.value ?? 'The VRChat ID field could not be turned into a list, so nothing was changed.';
        return;
      }

      field = { ...field, type: 'identifier_list' };
      vrchat.value = { ...vrchat.value, field };
    }

    for (const id of ids) {
      // Looked up again each time: every link replaces the row.
      const target = connections.value.find((c) => c.id === targetId);

      if (!target || (await linkFriend(target, id, field)) !== 'linked') {
        mergeError.value = 'That connection already has a different VRChat ID, so nothing was deleted.';
        return;
      }
    }

    const names = `${decryptedNames.value[sourceId] || 'The connection'} into ${decryptedNames.value[targetId] || 'the other connection'}`;
    await axios.delete(`/dashboard/connections/${sourceId}`);
    newConnectionIds.value = newConnectionIds.value.filter((id) => id !== sourceId);
    onConnectionDeleted(sourceId);
    selectedConnectionId.value = targetId;
    mergeOpen.value = false;
    friendNotice.value = `Merged ${names}: moved ${ids.length} VRChat ID${ids.length === 1 ? '' : 's'} and deleted the original.`;
  } catch (error) {
    console.error(error);
    mergeError.value = 'The merge could not be completed. Check the other connection, then try again (the original is only deleted after its IDs were added).';
  } finally {
    mergeBusy.value = false;
  }
}

/** Carries out the owner's choices from FriendReviewModal, converting the VRChat field to a list first if one of them needs it. */
async function applyReview(choices: Record<string, ReviewChoice>): Promise<void> {
  const items = reviewItems.value;
  const field = vrchat.value.field;
  if (reviewBusy.value || !field || items.length === 0) return;

  reviewBusy.value = true;
  let created = 0;
  let linked = 0;
  const skipped: string[] = [];
  const done: string[] = [];
  const skippedIds: string[] = [];
  const handledIds = new Set<string>();

  try {
    let currentField = field;

    if (currentField.type === 'identifier' && needsListConversion(items, choices)) {
      await convertDefinitionToList(currentField.id);

      if (definitionItems.value.find((d) => d.id === currentField.id)?.type !== 'identifier_list') {
        // The conversion failed (the reason is in definitionError): change nothing.
        return;
      }

      currentField = { ...currentField, type: 'identifier_list' };
      vrchat.value = { ...vrchat.value, field: currentField };
    }

    for (const item of items) {
      const choice = choices[item.friendId] ?? 'new';
      const friend: PendingFriend = { id: item.friendId, vrchat_user_id: item.vrchatId, display_name: item.name };

      try {
        if (choice === 'skip') {
          skippedIds.push(item.friendId);
          skipped.push(item.name);
        } else if (choice === 'new') {
          await createFriendConnection(friend, currentField, reviewSourceId);
          done.push(item.friendId);
          created++;
        } else {
          const connection = connections.value.find((c) => c.id === choice);

          if (connection && (await linkFriend(connection, item.vrchatId, currentField)) === 'linked') {
            done.push(item.friendId);
            linked++;
          } else {
            continue;
          }
        }

        handledIds.add(item.friendId);
      } catch (error) {
        console.error(error);
      }
    }

    if (done.length || skippedIds.length) {
      await axios.post('/dashboard/vrchat/friends/complete', { ids: done, skipped: skippedIds });
    }
  } catch (error) {
    console.error(error);
  } finally {
    reviewBusy.value = false;
  }

  reviewItems.value = items.filter((item) => !handledIds.has(item.friendId));
  if (reviewItems.value.length === 0) reviewOpen.value = false;

  showFriendNotice(created, linked, skipped, reviewItems.value.length);

  try {
    vrchat.value = (await axios.get<VrchatSummary>('/dashboard/vrchat')).data;
  } catch (error) {
    console.error(error);
  }
}

watch([vaultUnlocked, () => vrchat.value.pending_friends], createFriendConnections, { immediate: true });

/** A saved connection comes back with the VRChat accounts that were looked up as part of the save: show them at once. */
function applyVrchatProfiles(row: ConnectionRow): void {
  if (!row.vrchat_profiles) return;

  vrchat.value = { ...vrchat.value, profiles: { ...vrchat.value.profiles, [row.id]: row.vrchat_profiles } };
}

function onConnectionUpdated(updated: ConnectionRow): void {
  const index = connections.value.findIndex((c) => c.id === updated.id);
  if (index !== -1) connections.value[index] = updated;
  applyVrchatProfiles(updated);
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

/** Returns the new field's id, or null when it could not be created. */
async function addDefinition(label: string, type: string, choices: string[]): Promise<string | null> {
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
    return id;
  } catch (error) {
    console.error(error);
    return null;
  }
}

/** The target field doesn't exist yet: make it, then run the usual (non-destructive) copy into it. */
async function createIdentifierAndCopy(sourceId: string, label: string): Promise<void> {
  const id = await addDefinition(label, 'identifier', []);

  if (id === null) {
    copyStatus.value = 'Could not create the identifier field.';
    return;
  }

  await copyToIdentifier(sourceId, id);
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

/**
 * One-way, out of E2EE: decrypts every connection's value for the text field
 * here, then hands the plaintext to the server, which swaps it in for the
 * ciphertext in one all-or-nothing step. If any value can't be decrypted
 * nothing is sent; if the server finds its data moved on, it changes nothing.
 */
async function convertDefinitionToIdentifier(id: string): Promise<void> {
  definitionError.value = null;
  copyBusy.value = true;

  try {
    const values: { connection_id: string; identifier_value: string }[] = [];

    for (const connection of connections.value) {
      const row = connection.attribute_values.find((v) => v.attribute_definition_id === id);
      if (!row) continue;

      // A value that can't be decrypted aborts everything: sending it as blank would delete it.
      const value = row.value_ciphertext ? await decryptString(await getRecordKey(connection.id), row.value_ciphertext) : '';
      values.push({ connection_id: connection.id, identifier_value: value.trim() });
    }

    await axios.post(`/dashboard/connection-attribute-definitions/${id}/convert-to-identifier`, { values });

    const definition = definitionItems.value.find((d) => d.id === id);
    if (definition) definition.type = 'identifier';

    const converted = new Map(values.map((v) => [v.connection_id, v.identifier_value]));
    connections.value = connections.value.map((c) => ({
      ...c,
      attribute_values: c.attribute_values.flatMap((v) => {
        if (v.attribute_definition_id !== id) return [v];
        const value = converted.get(c.id) ?? '';
        return value ? [{ attribute_definition_id: id, value_ciphertext: null, identifier_value: value }] : [];
      }),
    }));
  } catch (error) {
    console.error(error);
    definitionError.value = axios.isAxiosError(error)
      ? (error.response?.data?.errors?.definition?.[0] ?? 'This field could not be converted.')
      : 'A value could not be decrypted, so nothing was converted.';
  } finally {
    copyBusy.value = false;
  }
}

/**
 * One-way: the server turns every value of the field into a one-entry list
 * (dropping empty ones) and flips its type. Mirror that locally so nothing
 * needs a reload.
 */
async function convertDefinitionToList(id: string): Promise<void> {
  definitionError.value = null;

  try {
    await axios.post(`/dashboard/connection-attribute-definitions/${id}/convert-to-list`);

    const definition = definitionItems.value.find((d) => d.id === id);
    if (definition) definition.type = 'identifier_list';

    connections.value = connections.value.map((c) => ({
      ...c,
      attribute_values: c.attribute_values.flatMap((v) => {
        if (v.attribute_definition_id !== id) return [v];
        const value = (v.identifier_value ?? '').trim();
        return value ? [{ ...v, identifier_value: null, identifier_list_value: [value] }] : [];
      }),
    }));
  } catch (error) {
    console.error(error);
    definitionError.value = axios.isAxiosError(error)
      ? (error.response?.data?.errors?.definition?.[0] ?? 'This field could not be converted.')
      : 'This field could not be converted.';
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

  <PageHeader title="Connections" subtitle="Your private CRM, end-to-end encrypted." />

  <BAlert :model-value="friendNotice !== ''" variant="success" dismissible @close="friendNotice = ''">
    {{ friendNotice }}
    <BButton v-if="reviewItems.length && !reviewOpen" size="sm" variant="outline-success" class="ms-2" @click="reviewOpen = true">Review</BButton>
  </BAlert>
  <MergeConnectionModal
    v-model:open="mergeOpen"
    :source-name="mergeSource ? (decryptedNames[mergeSource.id] ?? '') : ''"
    :ids="mergeSource ? vrchatIdsOf(mergeSource) : []"
    :options="connectionOptions.filter((c) => c.id !== mergeSourceId)"
    :single-field="vrchat.field?.type === 'identifier'"
    :busy="mergeBusy"
    :error="mergeError"
    @merge="mergeConnectionInto"
  />
  <FriendReviewModal
    v-model:open="reviewOpen"
    :items="reviewItems"
    :field-type="vrchat.field?.type ?? ''"
    :busy="reviewBusy"
    @apply="applyReview"
  />

  <BCard id="connections-workspace" class="mb-4">
    <BTabs v-model="pageTab" pills no-fade content-class="pt-3">
      <BTab :id="PAGE_TABS.connections">
        <template #title>Connections <BBadge variant="secondary" pill class="ms-1">{{ connections.length }}</BBadge></template>
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
                    :class="selectedConnectionId === connection.id ? '' : connection.share_link.last_visited_at ? 'text-info' : 'text-muted'"
                    :title="connection.share_link.last_visited_at ? 'Has a share link' : 'Has a share link (never visited)'"
                  >
                    <FontAwesomeIcon :icon="faLink" />
                    <span class="visually-hidden">{{ connection.share_link.last_visited_at ? 'Has a share link' : 'Has a share link (never visited)' }}</span>
                  </span>
                  <!-- Made from a VRChat friend during this visit (listed first). -->
                  <span
                    v-if="newConnectionIds.includes(connection.id)"
                    class="ms-1 connection-new"
                    :class="selectedConnectionId === connection.id ? '' : 'text-success'"
                    title="Added from your VRChat friends on this visit"
                  >
                    <FontAwesomeIcon :icon="faUserPlus" />
                    <span class="visually-hidden">Added from your VRChat friends on this visit</span>
                  </span>
                  <!-- Archived. Muted, except on the selected row, where the inherited white stays readable. -->
                  <span
                    v-if="connection.archived"
                    class="ms-1 connection-archived"
                    :class="selectedConnectionId === connection.id ? '' : 'text-warning'"
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
                :vrchat-profiles="vrchat.profiles[selectedConnection.id]"
                :vrchat-synced-at="vrchat.synced_at"
                @vrchat-synced="vrchat = $event"
                @updated="onConnectionUpdated"
                @renamed="onConnectionRenamed"
                :can-merge="vrchat.field !== null"
                @merge="openMerge"
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
        <template #title>Sources <BBadge variant="secondary" pill class="ms-1">{{ sourceItems.length }}</BBadge></template>
        <SourcesPanel v-model:selected-id="selectedSourceId" :sources="sourceItems" :categories="categories" @add="addSource" @update="updateSource" @remove="removeSource" />
      </BTab>

      <BTab :id="PAGE_TABS.categories">
        <template #title>Categories <BBadge variant="secondary" pill class="ms-1">{{ categoryItems.length }}</BBadge></template>
        <CategoriesPanel v-model:selected-id="selectedCategoryId" :categories="categoryItems" @add="addCategory" @update="updateCategory" @remove="removeCategory" />
      </BTab>

      <BTab :id="PAGE_TABS.attributes">
        <template #title>Custom attributes <BBadge variant="secondary" pill class="ms-1">{{ definitionItems.length }}</BBadge></template>
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
          @convert-to-identifier="convertDefinitionToIdentifier"
          @convert-to-list="convertDefinitionToList"
          @copy-to-identifier="copyToIdentifier"
          @create-identifier-and-copy="createIdentifierAndCopy"
          @sweep-orphans="sweepOrphans"
        />
      </BTab>
    </BTabs>
  </BCard>
</template>
