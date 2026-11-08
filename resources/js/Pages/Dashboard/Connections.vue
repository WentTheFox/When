<script setup lang="ts">
import { ArchiveIcon, LinkIcon, RectangleGogglesIcon, UserPlusIcon } from '@lucide/vue';
import { DiscordMark } from '../../Components/BrandIcons';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import { BAlert, BBadge, BButton, BCard, BFormGroup, BFormInput, BTab, BTabs } from 'bootstrap-vue-next';
import { computed, nextTick, onBeforeUnmount, onMounted, type Ref, ref, watch } from 'vue';
import { decryptString, encryptString } from '../../crypto';
import { matchConnection, parseAlternativeNames } from '../../dashboard/alternativeNames';
import {
  BOOLEAN_CHOICES,
  choiceOfBoolean,
  convertedText,
  identifierEntriesOf,
  identifierOf,
  listTextOf,
  type MigrationPath,
  rulesFor,
} from '../../dashboard/fieldMigrations';
import { useResolvedTheme } from '../../composables/useTheme';
import { requestConfirm } from '../../dashboard/confirmModal';
import PageHeader from '../../Components/PageHeader.vue';
import AppIcon from '../../Components/AppIcon.vue';
import SearchableSelect from '../../Components/SearchableSelect.vue';
import AttributesPanel from '../../dashboard/AttributesPanel.vue';
import CategoriesPanel from '../../dashboard/CategoriesPanel.vue';
import EncryptedText from '../../Components/EncryptedText.vue';
import ConnectionCard, { type ConnectionRow, type PeerUpdate } from '../../dashboard/ConnectionCard.vue';
import SourcesPanel from '../../dashboard/SourcesPanel.vue';
import { discordMark, type MarkState, type ShareLinkMark, shareLinkMark, vrchatMark } from '../../dashboard/connectionMarks';
import PendingDiscordFriendsPanel from '../../dashboard/PendingDiscordFriendsPanel.vue';
import PendingFriendsPanel from '../../dashboard/PendingFriendsPanel.vue';
import VrchatNotesPanel, { type NoteImportItem } from '../../dashboard/VrchatNotesPanel.vue';
import { appendNote } from '../../dashboard/vrchatNotes';
import { moveLegacyNotes, NOTES_FIELD_LABEL, notesTextOf, saveNotes } from '../../dashboard/notesField';
import type { NotesFieldDeps, NotesFieldResponse } from '../../dashboard/notesField';
import { moveLegacyRelationships, RELATIONSHIPS_FIELD_LABEL, VALUES_PER_CALL } from '../../dashboard/relationshipsField';
import type { LegacyEdge, RelationshipsDeps, RelationshipsResponse } from '../../dashboard/relationshipsField';
import { listEntries, paddedListText } from '../../dashboard/encryptedList';
import { holdsOtherId, type MatchCandidate } from '../../dashboard/friendMatching';
import { type DiscordProfile, type DiscordSummary, emptyDiscordSummary, discordFriendName, type PendingDiscordFriend } from '../../dashboard/discord';
import type { FriendDecision, PendingFriend, VrchatSummary } from '../../dashboard/vrchat';
import {
  buildConnectionsHash,
  buildConnectionsSearch,
  hasRecord,
  PAGE_TABS,
  parseConnectionsHash,
  type PageTabName,
} from '../../dashboard/connectionsHash';
import LockedOverlay from '../../dashboard/LockedOverlay.vue';
import { alphabetical, type LabelOf, placeAlphabetically, sameOrder } from '../../dashboard/listOrder';
import { requestUnlock } from '../../dashboard/vaultModal';
import { useRuleIcons } from '../../dashboard/useRuleIcons';
import { useVault } from '../../dashboard/useVault';
import { swatchByKey } from '../../free/color-palette';
import RuleIcon from '../../Components/RuleIcon.vue';
import { flagIconCode } from '../../dashboard/countries';
import { paletteIconFor } from '../../free/icon-palette';
import { type IconRuleSet, serializeIconRules, type ResolvedIcon } from '../../dashboard/iconRules';
import DashboardLayout from '../../Layouts/DashboardLayout.vue';

defineOptions({ layout: DashboardLayout });

interface SourceRow { id: string; category_id: string | null; name_ciphertext: string }
interface CategoryRow { id: string; color_key: string | null; name_ciphertext: string }
interface DefinitionRow { id: string; label_ciphertext: string; type: string; options_ciphertext: string | null; icon_rules_ciphertext: string | null }

const props = defineProps<{
  connections: ConnectionRow[];
  sources: SourceRow[];
  categories: CategoryRow[];
  attributeDefinitions: DefinitionRow[];
  /** The old plaintext "knows" edges, only until they have been moved into a relationship field (see relationshipsField.ts). */
  legacyEdges: LegacyEdge[];
  /** The field the old edges are moved into, once it exists. */
  relationshipsFieldId?: string | null;
  /** Fields that site features depend on (field id to feature labels); those can't be deleted. */
  fieldFeatures: Record<string, string[]>;
  /** Which field types each feature (by label) can use: a migration that leaves them unbinds the feature. */
  fieldFeatureTypes: Record<string, string[]>;
  /** Every type change a field may go through (the server's FieldMigrations). */
  fieldMigrations: MigrationPath[];
  /** Cached VRChat names, pictures and time together (§0.2 tier, not E2EE). */
  vrchat: VrchatSummary;
  /** The Discord connection and its friend count (§0.2 tier, not E2EE). Absent on a server without Discord. */
  discord?: DiscordSummary;
  /** The multi-line custom field that holds the notes (they used to be built in); null until there is one. */
  notesFieldId?: string | null;
  /** The identifier-list or multi-line field holding each connection's other names; the search matches on it too. */
  alternativeNamesFieldId?: string | null;
}>();

const { createRecordKey, getRecordKey, vaultUnlocked } = useVault();

const connections = ref<ConnectionRow[]>(props.connections);
const vrchat = ref<VrchatSummary>(props.vrchat);
const discord = ref<DiscordSummary>(props.discord ?? emptyDiscordSummary);
/**
 * The lists below are populated from the server's data right away, with every
 * name still as ciphertext (`label` undefined) — they show the raw blobs under
 * the locked overlay and fill in as the vault is unlocked and each item is
 * decrypted. The decrypted-only views further down are what the forms and
 * connection cards use.
 */
interface SourceItem { id: string; category_id: string | null; name_ciphertext: string; label?: string }
interface CategoryItem { id: string; color_key: string | null; name_ciphertext: string; label?: string }
interface DefinitionItem { id: string; type: string; label_ciphertext: string; options_ciphertext: string | null; icon_rules_ciphertext: string | null; label?: string; options: string[] }

const sourceItems = ref<SourceItem[]>(props.sources.map((s) => ({ ...s })));
const categoryItems = ref<CategoryItem[]>(props.categories.map((c) => ({ ...c })));
const definitionItems = ref<DefinitionItem[]>(props.attributeDefinitions.map((d) => ({ ...d, options: [] })));

/** The rule-driven icons (per-field icon rules, see iconRules.ts) shown after the fixed marks in each row. */
const { ruleIcons, ruleSets } = useRuleIcons(connections, definitionItems, vaultUnlocked, getRecordKey);
const resolvedTheme = useResolvedTheme();

/** Whether a rule icon key is a palette icon or a country flag this client can draw. */
function ruleIconKnown(key: string): boolean {
  return flagIconCode(key) !== null || paletteIconFor(key) !== undefined;
}

/** A rule icon's palette colour for the current theme; undefined (inherit) when it has none or the row is selected, where the inherited white stays readable. */
function ruleIconColor(icon: ResolvedIcon, selected: boolean): string | undefined {
  if (selected || !icon.color) return undefined;
  const swatch = swatchByKey(icon.color);

  return swatch ? swatch[resolvedTheme.value] : undefined;
}

const sources = computed(() =>
  sourceItems.value
    .filter((s): s is SourceItem & { label: string } => s.label !== undefined)
    // The category's color rides along so every source name can show its group's dot.
    .map(({ id, category_id, label }) => ({
      id,
      category_id,
      label,
      color_key: categoryItems.value.find((c) => c.id === category_id)?.color_key ?? null,
    })),
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
/** The edges still waiting to be moved; emptied once the move has finished. */
const legacyEdges = ref<LegacyEdge[]>(props.legacyEdges.map((edge) => ({ ...edge })));
const relationshipsFieldId = ref<string | null>(props.relationshipsFieldId ?? null);
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
const initialHash = typeof window === 'undefined' ? { tab: 'connections' as PageTabName } : parseConnectionsHash(window.location.hash, window.location.search);
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

/** The share-link mark: red when a highlight-tokens field is chosen and this connection has no tokens in it (see connectionMarks.ts). */
function shareLinkState(connection: ConnectionRow): ShareLinkMark {
  const fieldId = vrchat.value.highlight_field_id;
  const tokens = fieldId ? connection.attribute_values.find((v) => v.attribute_definition_id === fieldId)?.identifier_list_value : null;

  return shareLinkMark(!!connection.share_link?.last_visited_at, fieldId !== null, tokens);
}

const SHARE_LINK_TEXT: Record<ShareLinkMark, string> = {
  unhighlighted: 'Has a share link, but no highlight tokens, so it highlights nothing',
  visited: 'Has a share link',
  idle: 'Has a share link (never visited)',
};

/** The mark for a connection's VRChat ID: none, muted (set but unconfirmed) or active (VRCX knows the account). See connectionMarks.ts. */
function vrchatState(connection: ConnectionRow): MarkState {
  return vrchatMark(vrchatIdsOf(connection).length > 0, vrchat.value.profiles[connection.id]);
}

/** A connection's Discord card looked its IDs up: show what came back at once. */
function onDiscordSynced(connectionId: string, profiles: DiscordProfile[]): void {
  discord.value = { ...discord.value, profiles: { ...discord.value.profiles, [connectionId]: profiles } };
}

/** The mark for a connection's Discord ID: from the chosen custom field, or reported by VRCX. */
function discordState(connection: ConnectionRow): MarkState {
  // A synced Discord friend is linked to it: the account is confirmed, whichever way the id got there.
  if (discord.value.profiles[connection.id]?.some((p) => p.known)) return 'active';

  const fieldId = vrchat.value.discord_field_id;
  const value = fieldId ? connection.attribute_values.find((v) => v.attribute_definition_id === fieldId) : undefined;
  const filled = !!value && ((value.identifier_value ?? '').trim() !== '' || (value.identifier_list_value ?? []).some((v) => v.trim() !== ''));

  return discordMark(filled, vrchat.value.profiles[connection.id]);
}

/**
 * Each connection's other names, from the field chosen in Settings. An identifier list arrives as plain
 * values; a multi-line field is E2EE, so it is decrypted here once the vault is open.
 */
const alternativeNames = ref<Record<string, string[]>>({});
let alternativeNamesRun = 0;

watch(
  () => [
    vaultUnlocked.value,
    props.alternativeNamesFieldId,
    connections.value
      .map((c) => {
        const value = c.attribute_values.find((v) => v.attribute_definition_id === props.alternativeNamesFieldId);
        return `${c.id}:${value?.value_ciphertext ?? ''}:${(value?.identifier_list_value ?? []).join('\n')}`;
      })
      .join('|'),
  ],
  async () => {
    const run = ++alternativeNamesRun;
    const fieldId = props.alternativeNamesFieldId ?? null;

    if (!fieldId) {
      alternativeNames.value = {};
      return;
    }

    const next: Record<string, string[]> = {};

    for (const connection of connections.value) {
      const value = connection.attribute_values.find((v) => v.attribute_definition_id === fieldId);
      if (!value) continue;

      if (value.identifier_list_value) {
        next[connection.id] = parseAlternativeNames(value.identifier_list_value);
      } else if (value.value_ciphertext && vaultUnlocked.value) {
        try {
          next[connection.id] = parseAlternativeNames(await decryptString(await getRecordKey(connection.id), value.value_ciphertext));
        } catch (error) {
          console.error(error);
        }
      }
    }

    // A newer change may have started while this one decrypted: only the latest result is kept.
    if (run === alternativeNamesRun) alternativeNames.value = next;
  },
  { immediate: true },
);

/** The search, applied to one connection: whether it matches and, if only an other name did, which one. */
function searchMatch(connection: ConnectionRow, query: string): { matches: boolean; via: string | null } {
  return matchConnection(decryptedNames.value[connection.id] ?? '', alternativeNames.value[connection.id] ?? [], query);
}

/** The other name that made a connection match the current search, if the real name didn't. */
function matchedAlternativeName(connection: ConnectionRow): string | null {
  const query = connectionFilter.value.trim().toLowerCase();

  return query ? searchMatch(connection, query).via : null;
}

const filteredConnections = computed(() => {
  const query = connectionFilter.value.trim().toLowerCase();
  const matching = query ? connections.value.filter((c) => searchMatch(c, query).matches) : connections.value;

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

  await normalizeAllLists();
}, { immediate: true });

/**
 * The active tab and the record open in it live in the URL (`?tab=` and `#id=`, see
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
  const state = { tab, id: selectionFor(tab).value ?? undefined };
  const hash = buildConnectionsHash(state);
  const search = buildConnectionsSearch(state, window.location.search);

  if (window.location.hash === hash && window.location.search === search) return;

  window.history.replaceState(window.history.state, '', `${window.location.pathname}${search}${hash}`);
}

watch([pageTab, selectedConnectionId, selectedSourceId, selectedCategoryId, selectedDefinitionId], writeHash);
watch(selectedDefinitionId, () => { definitionError.value = null; });

/** Applies whatever the hash names (a hand-edited hash or an in-page link). */
function applyHash(): void {
  const state = parseConnectionsHash(window.location.hash, window.location.search);
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
  if (!hasRecord(parseConnectionsHash(window.location.hash, window.location.search))) return;

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
    });

    connections.value.push(data);
    applyVrchatProfiles(data);
    decryptedNames.value[id] = newName.value;
    selectedConnectionId.value = id;
    void placeAndSave(connections, 'connections', id, labelOfConnection);

    newName.value = '';
    newSourceIds.value = [];
    showNewForm.value = false;
    connectionFilter.value = '';
  } catch (error) {
    console.error(error);
    newConnectionError.value = 'Could not create that connection.';
  }
}

const friendNotice = ref('');

/** The friends waiting for the owner (persisted on the server, so they are listed again after every refresh until acted on). */
const pendingFriends = ref<PendingFriend[]>([]);
const friendsSourceId = ref<string | null>(null);
const friendsBusy = ref(false);
const friendCandidates = ref<MatchCandidate[]>([]);
const friendsReady = ref(false);

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

/** Makes a connection for a friend: named after them, their id (VRChat or Discord) in the field, the Settings source tied to it. */
async function createFriendConnection(name: string, externalId: string, field: { id: string; type: string }, sourceId: string | null): Promise<string> {
  const id = crypto.randomUUID();
  const key = await createRecordKey(id);
  const { data: row } = await axios.post('/dashboard/connections', {
    id,
    name_ciphertext: await encryptString(key, name),
    // The source chosen in Settings (the server omits it once that source is gone).
    source_ids: sourceId ? [sourceId] : [],
    attribute_values: [field.type === 'identifier_list'
      ? { attribute_definition_id: field.id, identifier_list_value: [externalId] }
      : { attribute_definition_id: field.id, identifier_value: externalId }],
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

function showFriendNotice(created: number, linked: number, dismissed: number, failed: number, label = 'VRChat friends'): void {
  const parts: string[] = [];
  if (created > 0) parts.push(`added ${created} connection${created === 1 ? '' : 's'}`);
  if (linked > 0) parts.push(`added ${linked} friend${linked === 1 ? '' : 's'} to existing connections`);
  if (dismissed > 0) parts.push(`dismissed ${dismissed}`);

  const sentences: string[] = [];
  if (parts.length > 0) sentences.push(`${label}: ${parts.join(', ')}.`);
  if (failed > 0) sentences.push(`${failed} could not be done and ${failed === 1 ? 'is' : 'are'} still waiting.`);

  friendNotice.value = sentences.join(' ');
}

/** The friends list is only worth fetching when the feature is on and there is a field to put the IDs in. */
const friendsEnabled = computed(() => vrchat.value.auto_create_friends && vrchat.value.field !== null);

async function loadPendingFriends(): Promise<void> {
  if (!friendsEnabled.value) {
    pendingFriends.value = [];
    return;
  }

  try {
    const { data } = await axios.get<{ friends: PendingFriend[]; source_id: string | null }>('/dashboard/vrchat/friends');
    pendingFriends.value = data.friends;
    friendsSourceId.value = data.source_id;
  } catch (error) {
    console.error(error);
  }
}

/** Matching needs every connection's name, so it only runs once the vault is open. */
async function prepareFriendMatching(): Promise<void> {
  friendsReady.value = false;
  if (!vaultUnlocked.value || (pendingFriends.value.length === 0 && pendingDiscord.value.length === 0)) return;

  friendCandidates.value = await matchCandidates();
  friendsReady.value = true;
}

/**
 * The candidates are what the pending-friend lists match against and offer in their dropdowns, so they
 * have to follow the connections: one created by applying a friend (or by the New form) must be offered to
 * the friends still waiting, and match a later friend of the same name. Rebuilt whenever a connection is
 * added, removed or renamed; only once matching is ready (prepareFriendMatching does the first build).
 */
let candidateRun = 0;
watch(
  () => connections.value.map((c) => `${c.id}:${decryptedNames.value[c.id] ?? ''}`).join('|'),
  async () => {
    if (!friendsReady.value) return;

    const run = ++candidateRun;
    const next = await matchCandidates();

    // A newer change may have started a rebuild while this one waited: only the latest one is kept.
    if (run === candidateRun) friendCandidates.value = next;
  },
);

/** Would adding this VRChat id to that connection need the single identifier turned into a list? */
function friendNeedsConversion(connectionId: string, vrchatId: string): boolean {
  const field = vrchat.value.field;
  if (!field || field.type !== 'identifier') return false;

  const value = connections.value.find((c) => c.id === connectionId)?.attribute_values.find((v) => v.attribute_definition_id === field.id);

  return holdsOtherId(value?.identifier_value, vrchatId);
}

/** The VRChat ids a connection holds in the VRChat user ID field. */
function vrchatIdsOf(connection: ConnectionRow): string[] {
  const field = vrchat.value.field;
  if (!field) return [];

  const value = connection.attribute_values.find((v) => v.attribute_definition_id === field.id);
  const raw = [...(value?.identifier_list_value ?? []), value?.identifier_value ?? ''];

  return [...new Set(raw.map((id) => id.trim().toLowerCase()).filter((id) => /^usr_[0-9a-f-]+$/.test(id)))];
}

/**
 * Carries out the owner's choices for pending friends: a new connection, the friend's
 * ID added to an existing one, or a dismissal. If an existing connection already holds a
 * different ID in a single-identifier field, the owner confirms first and the field becomes
 * an identifier list. A friend whose request fails stays pending.
 */
async function applyFriendDecisions(decisions: FriendDecision[]): Promise<void> {
  let field = vrchat.value.field;
  if (friendsBusy.value || !field || decisions.length === 0) return;

  const friends = new Map(pendingFriends.value.map((f) => [f.id, f]));
  const chosen = decisions.filter((d) => friends.has(d.friendId));

  friendsBusy.value = true;
  let created = 0;
  let linked = 0;
  let dismissed = 0;
  let failed = 0;
  const done: string[] = [];
  const skipped: string[] = [];

  try {
    const conflicts = chosen.some((d) => d.choice !== 'new' && d.choice !== 'skip' && friendNeedsConversion(d.choice, friends.get(d.friendId)!.vrchat_user_id));

    if (conflicts) {
      const confirmed = await requestConfirm({
        title: 'Turn the VRChat ID field into a list?',
        message: 'At least one connection you chose already has a different VRChat ID, and the field holds only one. It becomes an identifier list first (every connection keeps its ID as a one-entry list). This cannot be undone.',
        confirmText: 'Convert and continue',
        variant: 'primary',
      });
      if (!confirmed) return;

      await migrateDefinition(field.id, 'identifier_list');

      if (definitionItems.value.find((d) => d.id === field!.id)?.type !== 'identifier_list') {
        friendNotice.value = definitionError.value ?? 'The VRChat ID field could not be turned into a list, so nothing was changed.';
        return;
      }

      field = { ...field, type: 'identifier_list' };
      vrchat.value = { ...vrchat.value, field };
    }

    for (const decision of chosen) {
      const friend = friends.get(decision.friendId)!;

      try {
        if (decision.choice === 'skip') {
          skipped.push(friend.id);
          dismissed++;
        } else if (decision.choice === 'new') {
          await createFriendConnection(friend.display_name || friend.vrchat_user_id, friend.vrchat_user_id, field, friendsSourceId.value);
          done.push(friend.id);
          created++;
        } else {
          const connection = connections.value.find((c) => c.id === decision.choice);

          if (connection && (await linkFriend(connection, friend.vrchat_user_id, field)) === 'linked') {
            done.push(friend.id);
            linked++;
          } else {
            failed++;
          }
        }
      } catch (error) {
        console.error(error);
        failed++;
      }
    }

    if (done.length || skipped.length) {
      await axios.post('/dashboard/vrchat/friends/complete', { ids: done, skipped });
    }
  } catch (error) {
    console.error(error);
  } finally {
    friendsBusy.value = false;
  }

  showFriendNotice(created, linked, dismissed, failed);

  const handled = new Set([...done, ...skipped]);
  pendingFriends.value = pendingFriends.value.filter((f) => !handled.has(f.id));

  try {
    vrchat.value = (await axios.get<VrchatSummary>('/dashboard/vrchat')).data;
  } catch (error) {
    console.error(error);
  }
}

/** Discord friends waiting for the owner (persisted on the server, listed again after every refresh until acted on). */
const pendingDiscord = ref<PendingDiscordFriend[]>([]);
const discordBusy = ref(false);

/** The list is only worth fetching once Discord is connected and there is a field to put the IDs in. */
const discordEnabled = computed(() => discord.value.configured && discord.value.field !== null);

async function loadPendingDiscord(): Promise<void> {
  if (!discordEnabled.value) {
    pendingDiscord.value = [];
    return;
  }

  try {
    const { data } = await axios.get<{ friends: PendingDiscordFriend[] }>('/dashboard/discord/friends');
    pendingDiscord.value = data.friends;
  } catch (error) {
    console.error(error);
  }
}

// An imported (unchecked) avatar that fails to load asks the server to check that person with Discord, once per page load.
const refreshedDiscord = new Set<string>();

async function refreshDiscordFriend(friend: PendingDiscordFriend): Promise<void> {
  if (refreshedDiscord.has(friend.id)) return;

  refreshedDiscord.add(friend.id);

  try {
    const { data } = await axios.post<{ friend: PendingDiscordFriend }>(`/dashboard/discord/friends/${friend.id}/refresh`);
    pendingDiscord.value = pendingDiscord.value.map((f) => (f.id === friend.id ? data.friend : f));
  } catch {
    // Not set up on this server, rate limited, or Discord no longer knows them: the row simply stays as imported.
  }
}

/** Would adding this Discord id to that connection need the single identifier turned into a list? */
function discordNeedsConversion(connectionId: string, discordId: string): boolean {
  const field = discord.value.field;
  if (!field || field.type !== 'identifier') return false;

  const value = connections.value.find((c) => c.id === connectionId)?.attribute_values.find((v) => v.attribute_definition_id === field.id);

  return holdsOtherId(value?.identifier_value, discordId);
}

/**
 * Carries out the owner's choices for pending Discord friends: a new connection (named after
 * your nickname for them, else their display name), the friend's ID added to an existing one,
 * or a dismissal. Same rules as the VRChat list: a single-identifier field that already holds a
 * different ID is turned into a list after confirming, and a friend whose request fails stays pending.
 */
async function applyDiscordDecisions(decisions: FriendDecision[]): Promise<void> {
  let field = discord.value.field;
  if (discordBusy.value || !field || decisions.length === 0) return;

  const friends = new Map(pendingDiscord.value.map((f) => [f.id, f]));
  const chosen = decisions.filter((d) => friends.has(d.friendId));

  discordBusy.value = true;
  let created = 0;
  let linked = 0;
  let dismissed = 0;
  let failed = 0;
  const done: string[] = [];
  const skipped: string[] = [];

  try {
    const conflicts = chosen.some((d) => d.choice !== 'new' && d.choice !== 'skip' && discordNeedsConversion(d.choice, friends.get(d.friendId)!.discord_user_id));

    if (conflicts) {
      const confirmed = await requestConfirm({
        title: 'Turn the Discord ID field into a list?',
        message: 'At least one connection you chose already has a different Discord ID, and the field holds only one. It becomes an identifier list first (every connection keeps its ID as a one-entry list). This cannot be undone.',
        confirmText: 'Convert and continue',
        variant: 'primary',
      });
      if (!confirmed) return;

      await migrateDefinition(field.id, 'identifier_list');

      if (definitionItems.value.find((d) => d.id === field!.id)?.type !== 'identifier_list') {
        friendNotice.value = definitionError.value ?? 'The Discord ID field could not be turned into a list, so nothing was changed.';
        return;
      }

      field = { ...field, type: 'identifier_list' };
      discord.value = { ...discord.value, field };
    }

    for (const decision of chosen) {
      const friend = friends.get(decision.friendId)!;

      try {
        if (decision.choice === 'skip') {
          skipped.push(friend.id);
          dismissed++;
        } else if (decision.choice === 'new') {
          await createFriendConnection(discordFriendName(friend), friend.discord_user_id, field, null);
          done.push(friend.id);
          created++;
        } else {
          const connection = connections.value.find((c) => c.id === decision.choice);

          if (connection && (await linkFriend(connection, friend.discord_user_id, field)) === 'linked') {
            done.push(friend.id);
            linked++;
          } else {
            failed++;
          }
        }
      } catch (error) {
        console.error(error);
        failed++;
      }
    }

    if (done.length || skipped.length) {
      await axios.post('/dashboard/discord/friends/complete', { ids: done, skipped });
    }
  } catch (error) {
    console.error(error);
  } finally {
    discordBusy.value = false;
  }

  showFriendNotice(created, linked, dismissed, failed, 'Discord friends');

  const handled = new Set([...done, ...skipped]);
  pendingDiscord.value = pendingDiscord.value.filter((f) => !handled.has(f.id));

  try {
    discord.value = (await axios.get<DiscordSummary>('/dashboard/discord')).data;
  } catch (error) {
    console.error(error);
  }
}

/** Connections whose linked VRChat accounts have a note waiting to be carried over (see VrchatNotesPanel). */
const noteImports = computed<NoteImportItem[]>(() =>
  connections.value.flatMap((connection) => {
    const notes = (vrchat.value.profiles[connection.id] ?? [])
      .filter((p) => p.note)
      .map((p) => ({ vrchatId: p.vrchat_user_id, note: p.note as string }));

    return notes.length ? [{ connectionId: connection.id, name: decryptedNames.value[connection.id] ?? '', notes }] : [];
  }),
);
const notesBusy = ref(false);
const notesProgress = ref('');

/**
 * The notes field (notes used to be a built-in property of a connection; see notesField.ts). Its first
 * call creates it, so a user with no notes gets it the first time something needs it.
 */
const notesFieldId = ref<string | null>(props.notesFieldId ?? null);

const notesDeps: NotesFieldDeps<ConnectionRow> = {
  fieldId: () => notesFieldId.value,
  connections: () => connections.value,
  getRecordKey,
  createRecordKey,
  encrypt: encryptString,
  decrypt: decryptString,
  post: async (body) => (await axios.post<NotesFieldResponse<ConnectionRow>>('/dashboard/connections/notes-field', body)).data,
  apply: async (response) => {
    const { definition } = response;
    notesFieldId.value = definition.id;

    if (!definitionItems.value.some((d) => d.id === definition.id)) {
      let label: string | undefined = response.created ? NOTES_FIELD_LABEL : undefined;
      if (label === undefined) {
        try {
          label = await decryptString(await getRecordKey(definition.id), definition.label_ciphertext);
        } catch (error) {
          console.error(error);
        }
      }

      definitionItems.value.push({ ...definition, options: [], label });
      if (label !== undefined) await placeAndSave(definitionItems, 'attributes', definition.id, labelOfItem);
    }

    for (const updated of response.connections) onConnectionUpdated(updated);
  },
};

const movingNotes = ref(false);

/** One time per owner, as soon as the vault is unlocked: old built-in notes become values of the Notes field. */
async function moveOldNotes(): Promise<void> {
  if (movingNotes.value || !vaultUnlocked.value) return;

  movingNotes.value = true;
  try {
    await moveLegacyNotes(notesDeps);
  } catch (error) {
    console.error(error);
  } finally {
    movingNotes.value = false;
  }
}

watch(vaultUnlocked, (unlocked) => {
  if (unlocked) void moveOldNotes();
}, { immediate: true });

/** Another connection's stored ciphertext in a relationship field, so a save can rewrite that connection's side too. */
function peerCiphertext(connectionId: string, definitionId: string): string | null {
  return connections.value.find((c) => c.id === connectionId)?.attribute_values.find((v) => v.attribute_definition_id === definitionId)?.value_ciphertext ?? null;
}

/** A save rewrote these connections' sides of relationship fields: keep the page's copy of them current. */
function onPeersUpdated(updates: PeerUpdate[]): void {
  for (const update of updates) {
    const index = connections.value.findIndex((c) => c.id === update.connection_id);
    if (index === -1) continue;

    const connection = connections.value[index];
    const others = connection.attribute_values.filter((v) => v.attribute_definition_id !== update.attribute_definition_id);
    connections.value[index] = {
      ...connection,
      attribute_values: update.value_ciphertext === null
        ? others
        : [...others, { attribute_definition_id: update.attribute_definition_id, value_ciphertext: update.value_ciphertext }],
    };
  }
}

const relationshipsDeps: RelationshipsDeps<ConnectionRow> = {
  fieldId: () => relationshipsFieldId.value,
  edges: () => legacyEdges.value,
  connections: () => connections.value,
  getRecordKey,
  createRecordKey,
  encrypt: encryptString,
  decrypt: decryptString,
  post: async (body) => (await axios.post<RelationshipsResponse<ConnectionRow>>('/dashboard/connections/relationships-field', body)).data,
  apply: async (response) => {
    const { definition } = response;
    relationshipsFieldId.value = definition.id;

    if (!definitionItems.value.some((d) => d.id === definition.id)) {
      let label: string | undefined = response.created ? RELATIONSHIPS_FIELD_LABEL : undefined;
      if (label === undefined) {
        try {
          label = await decryptString(await getRecordKey(definition.id), definition.label_ciphertext);
        } catch (error) {
          console.error(error);
        }
      }

      definitionItems.value.push({ ...definition, options: [], label });
      if (label !== undefined) await placeAndSave(definitionItems, 'attributes', definition.id, labelOfItem);
    }

    for (const updated of response.connections) onConnectionUpdated(updated);
  },
  finished: () => {
    legacyEdges.value = [];
  },
};

const movingRelationships = ref(false);

/** One time per owner, on the first unlock after the change: the old plaintext edges become a Relationships field. */
async function moveOldRelationships(): Promise<void> {
  if (movingRelationships.value || !vaultUnlocked.value) return;

  movingRelationships.value = true;
  try {
    await moveLegacyRelationships(relationshipsDeps);
  } catch (error) {
    console.error(error);
  } finally {
    movingRelationships.value = false;
  }
}

watch(vaultUnlocked, (unlocked) => {
  if (unlocked) void moveOldRelationships();
}, { immediate: true });

/**
 * Appends each waiting VRChat note to the connection's Notes field, on a new line, in this
 * browser (the notes are end-to-end encrypted), then tells the server which ones are done
 * so it can forget the plaintext. A connection whose notes can't be read or saved is left
 * as it was and stays waiting.
 */
async function importVrchatNotes(): Promise<void> {
  if (notesBusy.value || !vaultUnlocked.value) return;

  const work = noteImports.value;
  notesBusy.value = true;
  let appended = 0;
  let failed = 0;
  const imported: { connection_id: string; vrchat_user_id: string; note: string }[] = [];

  try {
    // Any note still in the old built-in place goes into the field first, so nothing is overwritten below.
    await moveLegacyNotes(notesDeps);

    const updates: { connectionId: string; text: string; imports: typeof imported }[] = [];

    for (const [index, item] of work.entries()) {
      notesProgress.value = `${index + 1} of ${work.length}`;
      const connection = connections.value.find((c) => c.id === item.connectionId);

      try {
        if (!connection) continue;

        let text = await notesTextOf(notesDeps, connection);
        let changed = false;

        for (const n of item.notes) {
          const next = appendNote(text, n.note);
          text = next.text;
          changed ||= next.changed;
        }

        const entries = item.notes.map((n) => ({ connection_id: connection.id, vrchat_user_id: n.vrchatId, note: n.note }));

        if (changed) updates.push({ connectionId: connection.id, text, imports: entries });
        else imported.push(...entries);
      } catch (error) {
        console.error(error);
        failed++;
      }
    }

    // One save for all of them (creating the field first if this is the first note ever). If it fails
    // none of them are marked done, so they all stay waiting.
    if (updates.length > 0) {
      try {
        await saveNotes(notesDeps, updates.map(({ connectionId, text }) => ({ connectionId, text })));
        appended = updates.length;
        for (const update of updates) imported.push(...update.imports);
      } catch (error) {
        console.error(error);
        failed += updates.length;
      }
    }

    for (let i = 0; i < imported.length; i += 500) {
      await axios.post('/dashboard/vrchat/notes/imported', { imported: imported.slice(i, i + 500) });
    }
  } catch (error) {
    console.error(error);
  } finally {
    notesBusy.value = false;
    notesProgress.value = '';
  }

  friendNotice.value = `VRChat notes: added to ${appended} connection${appended === 1 ? '' : 's'}`
    + `${imported.length > appended ? `, ${imported.length - appended} already there` : ''}`
    + `${failed ? `; ${failed} could not be imported and ${failed === 1 ? 'is' : 'are'} still waiting` : ''}.`;

  try {
    vrchat.value = (await axios.get<VrchatSummary>('/dashboard/vrchat')).data;
  } catch (error) {
    console.error(error);
  }
}

watch(vaultUnlocked, prepareFriendMatching);
onMounted(async () => {
  await Promise.all([loadPendingFriends(), loadPendingDiscord()]);
  await prepareFriendMatching();
});

/** A saved connection comes back with the VRChat accounts that were looked up as part of the save: show them at once. */
function applyVrchatProfiles(row: ConnectionRow): void {
  // The same save also reports the Discord accounts on it (placeholders, or looked up when the server can).
  if (row.discord_profiles) onDiscordSynced(row.id, row.discord_profiles);
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
  cleared += await clearOrphanedRelationships(liveIds);
  return cleared;
}

/**
 * Relationship lists live inside E2EE ciphertext too, and a deleted connection's id lingers in the other
 * connections' lists. Rewrites each affected list without the dead ids (the server only checks that nothing
 * changed since, and reports a changed one as a conflict, which the next sweep sees again).
 */
async function clearOrphanedRelationships(liveIds: Set<string>): Promise<number> {
  let cleared = 0;
  for (const definition of definitions.value.filter((d) => d.type === 'relationship')) {
    const values: { connection_id: string; value_ciphertext: string | null; base_ciphertext: string }[] = [];
    for (const connection of connections.value) {
      const row = connection.attribute_values.find((v) => v.attribute_definition_id === definition.id);
      if (!row?.value_ciphertext) continue;
      try {
        const key = await getRecordKey(connection.id);
        const entries = listEntries(await decryptString(key, row.value_ciphertext));
        const live = entries.filter((id) => liveIds.has(id));
        if (live.length === entries.length) continue;

        values.push({
          connection_id: connection.id,
          value_ciphertext: live.length === 0 ? null : await encryptString(key, paddedListText(live.join('\n'))),
          base_ciphertext: row.value_ciphertext,
        });
      } catch (error) {
        console.error(error);
      }
    }
    for (let i = 0; i < values.length; i += VALUES_PER_CALL) {
      const chunk = values.slice(i, i + VALUES_PER_CALL);
      try {
        const { data } = await axios.post<{ conflicts: Record<string, string | null> }>(`/dashboard/connection-attribute-definitions/${definition.id}/rewrite-values`, { values: chunk });
        const written = chunk.filter((v) => !(v.connection_id in data.conflicts));
        onPeersUpdated(written.map((v) => ({ connection_id: v.connection_id, attribute_definition_id: definition.id, value_ciphertext: v.value_ciphertext })));
        cleared += written.length;
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
    definitionItems.value.push({ id, type, label_ciphertext: labelCiphertext, options_ciphertext: optionsCiphertext, icon_rules_ciphertext: null, label, options: choices });
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

/** Additive only: the panel passes the existing choices (possibly reordered) plus any new ones. */
async function updateDefinitionOptions(id: string, choices: string[]): Promise<void> {
  try {
    const key = await getRecordKey(id);
    const optionsCiphertext = await encryptString(key, JSON.stringify({ choices }));
    await axios.patch(`/dashboard/connection-attribute-definitions/${id}`, { options_ciphertext: optionsCiphertext });
    const definition = definitionItems.value.find((d) => d.id === id);
    if (definition) {
      definition.options_ciphertext = optionsCiphertext;
      definition.options = choices;
    }
  } catch (error) {
    console.error(error);
  }
}

/** Icon rules: the whole rule set is one E2EE blob under the field's own record key. */
async function saveIconRules(id: string, set: IconRuleSet): Promise<void> {
  try {
    const key = await getRecordKey(id);
    const ciphertext = await encryptString(key, serializeIconRules(set));
    await axios.patch(`/dashboard/connection-attribute-definitions/${id}`, { icon_rules_ciphertext: ciphertext });
    const definition = definitionItems.value.find((d) => d.id === id);
    if (definition) definition.icon_rules_ciphertext = ciphertext;
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

interface MigrateOptions {
  /** identifier list to identifier: keep the first entry, or join them all. */
  strategy?: string;
  /** The owner agreed to unbind the features the new type can't serve. */
  releaseBindings?: boolean;
}

/** What a value of this field looks like once migrated; every other column is null, as the server writes it. */
type MigratedPatch = Partial<ConnectionRow['attribute_values'][number]>;

/** The plaintext-to-plaintext changes the server does alone; mirrored here so nothing needs a reload. */
function plainPatch(row: ConnectionRow['attribute_values'][number], to: string, strategy?: string): MigratedPatch | null {
  if (to === 'identifier_list') {
    const value = (row.identifier_value ?? '').trim();
    return value ? { identifier_list_value: [value] } : null;
  }

  const entries = (row.identifier_list_value ?? []).map((e) => e.trim()).filter((e) => e !== '');
  if (entries.length === 0) return null;

  return { identifier_value: (strategy === 'join' ? entries.join(', ') : entries[0]!).slice(0, 1000) };
}

/**
 * Changes a field's type in place (the Migrate field section; paths are the server's FieldMigrations). Values that
 * are E2EE can only be converted here: each is decrypted, converted (fieldMigrations.ts) and encrypted again, then the
 * server swaps them all in, flips the type and replaces the choices / icon rules in one all-or-nothing step. It
 * refuses (409) if any value changed since this page read it; a value that can't be decrypted aborts before anything is
 * sent. Returns whether the field was migrated.
 */
async function migrateDefinition(id: string, to: string, options: MigrateOptions = {}): Promise<boolean> {
  definitionError.value = null;
  const definition = definitionItems.value.find((d) => d.id === id);
  const path = props.fieldMigrations.find((p) => p.from === definition?.type && p.to === to);
  if (!definition || !path) {
    definitionError.value = 'This field can\'t be migrated to that type.';
    return false;
  }

  copyBusy.value = true;

  try {
    const from = definition.type;
    const definitionKey = await getRecordKey(id);
    const values: Record<string, unknown>[] = [];
    const patches = new Map<string, MigratedPatch | null>();

    for (const connection of connections.value) {
      const row = connection.attribute_values.find((v) => v.attribute_definition_id === id);
      if (!row) continue;

      if (path.tier === 'plain') {
        patches.set(connection.id, plainPatch(row, to, options.strategy));
        continue;
      }

      const key = await getRecordKey(connection.id);
      // A value that can't be decrypted aborts everything: sending it as blank would delete it.
      const plain = row.value_ciphertext ? await decryptString(key, row.value_ciphertext) : '';

      if (path.tier === 'to_e2ee') {
        const text = from === 'boolean' ? choiceOfBoolean(row.boolean_value) : listTextOf(row.identifier_list_value ?? []);
        const entry: Record<string, unknown> = { connection_id: connection.id, value_ciphertext: text === '' ? null : await encryptString(key, text) };
        if (from === 'boolean') entry.base_boolean = row.boolean_value === true;
        else entry.base_identifier_list = row.identifier_list_value ?? [];
        values.push(entry);
        patches.set(connection.id, entry.value_ciphertext ? { value_ciphertext: entry.value_ciphertext as string } : null);
      } else if (path.tier === 'to_plain') {
        const entries = to === 'identifier_list' ? identifierEntriesOf(plain) : null;
        const identifier = entries === null ? identifierOf(plain) : null;
        values.push({
          connection_id: connection.id,
          base_ciphertext: row.value_ciphertext,
          ...(entries === null ? { identifier_value: identifier } : { identifier_list_value: entries }),
        });
        const filled = entries === null ? identifier !== '' : entries.length > 0;
        patches.set(connection.id, !filled ? null : entries === null ? { identifier_value: identifier } : { identifier_list_value: entries });
      } else {
        const text = convertedText(from, to, plain);
        const ciphertext = text === '' ? null : await encryptString(key, text);
        values.push({ connection_id: connection.id, base_ciphertext: row.value_ciphertext, value_ciphertext: ciphertext });
        patches.set(connection.id, ciphertext ? { value_ciphertext: ciphertext } : null);
      }
    }

    const choices = to === 'radio' ? [...BOOLEAN_CHOICES] : [];
    const optionsCiphertext = to === 'radio' ? await encryptString(definitionKey, JSON.stringify({ choices })) : null;

    // Rules that make no sense for the new type would be ignored silently; drop them in the same step.
    let iconRulesCiphertext: string | null = null;
    const parsedRules = ruleSets.value[id];
    if (parsedRules?.kind === 'ok') {
      const kept = rulesFor(parsedRules.set, to);
      if (kept.rules.length !== parsedRules.set.rules.length) iconRulesCiphertext = await encryptString(definitionKey, serializeIconRules(kept));
    }

    const { data } = await axios.post(`/dashboard/connection-attribute-definitions/${id}/migrate`, {
      to,
      strategy: options.strategy,
      ...(path.tier === 'plain' ? {} : { values }),
      options_ciphertext: optionsCiphertext,
      icon_rules_ciphertext: iconRulesCiphertext,
      release_bindings: options.releaseBindings === true,
    });

    definition.type = to;
    definition.options_ciphertext = optionsCiphertext;
    definition.options = choices;
    if (iconRulesCiphertext) definition.icon_rules_ciphertext = iconRulesCiphertext;

    connections.value = connections.value.map((c) => ({
      ...c,
      attribute_values: c.attribute_values.flatMap((v) => {
        if (v.attribute_definition_id !== id) return [v];
        const patch = patches.get(c.id);
        if (!patch) return [];
        return [{ attribute_definition_id: id, value_ciphertext: null, identifier_value: null, identifier_list_value: null, boolean_value: null, ...patch }];
      }),
    }));

    if ((data.released as string[]).length > 0) router.reload({ only: ['fieldFeatures'] });

    return true;
  } catch (error) {
    console.error(error);
    definitionError.value = axios.isAxiosError(error)
      ? (error.response?.data?.errors?.definition?.[0] ?? error.response?.data?.message ?? 'This field could not be migrated.')
      : 'A value could not be decrypted, so nothing was migrated.';

    return false;
  } finally {
    copyBusy.value = false;
  }
}

</script>

<template>
  <Head title="Connections" />

  <PageHeader title="Connections" subtitle="Your private CRM, end-to-end encrypted." />

  <BAlert :model-value="friendNotice !== ''" variant="success" dismissible @close="friendNotice = ''">
    {{ friendNotice }}
  </BAlert>
  <VrchatNotesPanel
    v-if="noteImports.length"
    :items="noteImports"
    :ready="vaultUnlocked"
    :busy="notesBusy"
    :progress="notesProgress"
    @import="importVrchatNotes"
  />
  <PendingFriendsPanel
    v-if="friendsEnabled && pendingFriends.length"
    :friends="pendingFriends"
    :candidates="friendCandidates"
    :ready="friendsReady"
    :unlocked="vaultUnlocked"
    :single-field="vrchat.field?.type === 'identifier'"
    :needs-conversion="friendNeedsConversion"
    :busy="friendsBusy"
    @apply="applyFriendDecisions"
  />
  <PendingDiscordFriendsPanel
    v-if="discordEnabled && pendingDiscord.length"
    :friends="pendingDiscord"
    :candidates="friendCandidates"
    :ready="friendsReady"
    :unlocked="vaultUnlocked"
    :single-field="discord.field?.type === 'identifier'"
    :needs-conversion="discordNeedsConversion"
    :busy="discordBusy"
    @apply="applyDiscordDecisions"
    @avatar-failed="refreshDiscordFriend"
  />
  <BCard id="connections-workspace" class="mb-4">
    <BTabs v-model="pageTab" pills no-fade content-class="pt-3">
      <BTab :id="PAGE_TABS.connections">
        <template #title>Connections <BBadge variant="secondary" pill class="ms-1">{{ connections.length }}</BBadge></template>
        <div class="row">
          <div class="col-md-4 wtf-master-column">
            <LockedOverlay>
              <div class="input-group input-group-sm mb-2">
                <BFormInput v-model="connectionFilter" type="text" placeholder="Search or new connection name" />
                <BButton variant="outline-secondary" @click="toggleNewForm">New</BButton>
              </div>

              <BCard v-if="showNewForm" class="mb-2" body-class="p-2">
                <BFormGroup label="Name" label-class="small" class="mb-2">
                  <BFormInput v-model="newName" type="text" size="sm" />
                </BFormGroup>
                <BFormGroup label="Sources" label-class="small" class="mb-2">
                  <SearchableSelect
                    v-model="newSourceIds"
                    multiple
                    placeholder="(none)"
                    :options="sources.map((source) => ({ value: source.id, text: source.label, swatch: source.color_key }))"
                  />
                </BFormGroup>
                <BButton size="sm" variant="primary" @click="createConnection">Create</BButton>
                <div class="text-danger small mt-2">{{ newConnectionError }}</div>
              </BCard>

              <p v-if="filteredConnections.length === 0" class="text-muted wtf-master-list">No connections found.</p>
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
                  <!-- The search found this connection by another of its names. -->
                  <small v-if="matchedAlternativeName(connection)" class="ms-1 opacity-75">(also “{{ matchedAlternativeName(connection) }}”)</small>
                  <!-- Has a share link set up. The selected row is blue itself, so there it just inherits the white text. -->
                  <span
                    v-if="connection.share_link"
                    class="ms-1 connection-shared"
                    :class="selectedConnectionId === connection.id ? '' : { unhighlighted: 'text-danger', visited: 'text-info', idle: 'text-muted' }[shareLinkState(connection)]"
                    :style="selectedConnectionId === connection.id && shareLinkState(connection) === 'unhighlighted' ? 'color: #ff9e9e' : undefined"
                    :title="SHARE_LINK_TEXT[shareLinkState(connection)]"
                  >
                    <AppIcon :icon="LinkIcon" />
                    <span class="visually-hidden">{{ SHARE_LINK_TEXT[shareLinkState(connection)] }}</span>
                  </span>
                  <!-- VRChat / Discord IDs: muted when only set, colored when confirmed by VRCX. The selected row is blue itself, so there they are all just white. -->
                  <span
                    v-if="vrchatState(connection) !== 'none'"
                    class="ms-1 connection-vrchat"
                    :class="selectedConnectionId === connection.id ? '' : (vrchatState(connection) === 'active' ? 'text-info' : 'text-muted')"
                    :title="vrchatState(connection) === 'active' ? 'VRChat account linked (VRCX knows it)' : 'VRChat ID set, but VRCX has no profile for it yet'"
                  >
                    <AppIcon :icon="RectangleGogglesIcon" />
                    <span class="visually-hidden">{{ vrchatState(connection) === 'active' ? 'VRChat account linked' : 'VRChat ID set, not confirmed by VRCX' }}</span>
                  </span>
                  <span
                    v-if="discordState(connection) !== 'none'"
                    class="ms-1 connection-discord"
                    :class="selectedConnectionId === connection.id ? '' : (discordState(connection) === 'active' ? '' : 'text-muted')"
                    :style="selectedConnectionId !== connection.id && discordState(connection) === 'active' ? 'color: #5865f2' : undefined"
                    :title="discordState(connection) === 'active' ? 'Discord ID known' : 'Discord ID set in the custom field only'"
                  >
                    <DiscordMark />
                    <span class="visually-hidden">{{ discordState(connection) === 'active' ? 'Discord ID known' : 'Discord ID set, not confirmed by VRCX' }}</span>
                  </span>
                  <!-- Made from a VRChat friend during this visit (listed first). -->
                  <span
                    v-if="newConnectionIds.includes(connection.id)"
                    class="ms-1 connection-new"
                    :class="selectedConnectionId === connection.id ? '' : 'text-success'"
                    title="Added from your VRChat friends on this visit"
                  >
                    <AppIcon :icon="UserPlusIcon" />
                    <span class="visually-hidden">Added from your VRChat friends on this visit</span>
                  </span>
                  <!-- Archived. Muted, except on the selected row, where the inherited white stays readable. -->
                  <span
                    v-if="connection.archived"
                    class="ms-1 connection-archived"
                    :class="selectedConnectionId === connection.id ? '' : 'text-warning'"
                    title="Archived"
                  >
                    <AppIcon :icon="ArchiveIcon" />
                    <span class="visually-hidden">Archived</span>
                  </span>
                  <!-- Icons from the per-field icon rules (Fields tab). Evaluated in the browser against decrypted values. -->
                  <template v-for="icon in ruleIcons[connection.id] ?? []" :key="icon.ruleId">
                    <span
                      v-if="ruleIconKnown(icon.icon)"
                      class="ms-1 connection-rule-icon"
                      :class="selectedConnectionId === connection.id || icon.color ? '' : 'text-secondary'"
                      :style="{ color: ruleIconColor(icon, selectedConnectionId === connection.id) }"
                      :title="icon.label ?? undefined"
                    >
                      <RuleIcon :icon-key="icon.icon" />
                      <span v-if="icon.label" class="visually-hidden">{{ icon.label }}</span>
                    </span>
                  </template>
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
                :peer-ciphertext="peerCiphertext"
                :connection-options="connectionOptions"
                :vrchat-profiles="vrchat.profiles[selectedConnection.id]"
                :vrchat-synced-at="vrchat.synced_at"
                :discord-profiles="discord.profiles[selectedConnection.id]"
                :discord-synced-at="discord.synced_at"
                :discord-can-look-up="discord.lookup_available"
                @discord-synced="onDiscordSynced"
                @vrchat-synced="vrchat = $event"
                @updated="onConnectionUpdated"
                @renamed="onConnectionRenamed"
                @deleted="onConnectionDeleted"
                @peers-updated="onPeersUpdated"
                @open-connection="selectedConnectionId = $event"
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
          :field-feature-types="fieldFeatureTypes"
          :migrations="fieldMigrations"
          :remove-error="definitionError"
          :icon-rule-sets="ruleSets"
          :copy-status="copyStatus"
          :copy-busy="copyBusy"
          @add="addDefinition"
          @rename="renameDefinition"
          @update-options="updateDefinitionOptions"
          @save-icon-rules="saveIconRules"
          @remove="removeDefinition"
          @migrate="migrateDefinition"
          @copy-to-identifier="copyToIdentifier"
          @create-identifier-and-copy="createIdentifierAndCopy"
          @sweep-orphans="sweepOrphans"
        />
      </BTab>
    </BTabs>
  </BCard>
</template>
