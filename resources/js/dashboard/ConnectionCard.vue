<script setup lang="ts">
import { ArchiveIcon, ArrowRightIcon, ArchiveRestoreIcon, ExternalLinkIcon, Trash2Icon, UserMinusIcon } from '@lucide/vue';
import AppIcon from '../Components/AppIcon.vue';
import axios from 'axios';
import {
  BBadge,
  BButton,
  BCard,
  BFormGroup,
  BFormInvalidFeedback,
  BInputGroup,
  type InputType,
} from 'bootstrap-vue-next';
import { computed, ref, watch } from 'vue';
import { decryptString, encryptString } from '../crypto';
import CountrySelect from '../Components/CountrySelect.vue';
import SearchableSelect from '../Components/SearchableSelect.vue';
import EncryptedInput from '../Components/EncryptedInput.vue';
import RadioChoices from '../Components/RadioChoices.vue';
import EncryptedText from '../Components/EncryptedText.vue';
import ConnectionSharing, { type IdentityOption, type ShareLinkInfo } from './ConnectionSharing.vue';
import ConnectionDiscord from './ConnectionDiscord.vue';
import { listText, paddedListText } from './encryptedList';
import { invalidLinkLines, isValidWebUrl, parseWebUrl, validLinks } from './urlField';
import ConnectionVrchat from './ConnectionVrchat.vue';
import type { DiscordProfile } from './discord';
import type { VrchatProfile, VrchatSummary } from './vrchat';
import { requestConfirm } from './confirmModal';
import { bindField } from './fieldBinding';
import { onVaultUnlocked } from './onVaultUnlocked';
import { removalPrompt } from './relationships';
import { ATTRIBUTE_TYPE_ICONS } from './attributeTypeIcons';
import { VaultLockedError } from './vault';
import { useVault } from './useVault';

interface AttributeValue {
  attribute_definition_id: string;
  /** Null for identifier-type definitions — those use identifier_value instead. */
  value_ciphertext: string | null;
  /** Server-decrypted (§0.2 tier), not E2EE; only set for identifier-type definitions. */
  identifier_value?: string | null;
  /** Server-decrypted (§0.2 tier), not E2EE; only set for identifier_list-type definitions. */
  identifier_list_value?: string[] | null;
  /** Server-decrypted (§0.2 tier), not E2EE; only set for boolean-type definitions. */
  boolean_value?: boolean | null;
}

export interface ConnectionRow {
  id: string;
  source_ids: string[];
  name_ciphertext: string;
  /** Only ever set on a connection from before notes became a custom field; the page moves it into the Notes field on load (see notesField.ts). Never edited here. */
  notes_ciphertext: string | null;
  /** The connection's one optional share link; its name is this connection's name. */
  share_link: ShareLinkInfo | null;
  archived: boolean;
  attribute_values: AttributeValue[];
  /** Present on the response to a save: the VRChat accounts fetched as part of it. */
  vrchat_profiles?: VrchatProfile[];
  /** The Discord accounts on it as of this save: placeholders, or looked up (§0.2 tier). */
  discord_profiles?: DiscordProfile[];
}

export interface EdgeRow {
  id: string;
  from_connection_id: string;
  to_connection_id: string;
}

const props = defineProps<{
  connection: ConnectionRow;
  sources: { id: string; label: string; color_key?: string | null }[];
  attributeDefinitions: { id: string; label: string; type: string; options: string[] }[];
  edges: EdgeRow[];
  connectionOptions: { id: string; label: string }[];
  /** Linked VRChat accounts with cached profile and time together (§0.2 tier, not E2EE). */
  vrchatProfiles?: VrchatProfile[];
  vrchatSyncedAt?: string | null;
  /** Linked Discord friends with their avatar and names (§0.2 tier, not E2EE). */
  discordProfiles?: DiscordProfile[];
  discordSyncedAt?: string | null;
  /** The server can look a Discord ID up by itself, so the Discord card offers a Sync button. */
  discordCanLookUp?: boolean;
}>();
const emit = defineEmits<{
  updated: [ConnectionRow];
  /** The connection's name changed (id, new name), so the list can show it and re-place it. */
  renamed: [string, string];
  deleted: [string];
  addEdge: [string, string];
  removeEdge: [string];
  /** Open another connection's card (its id), e.g. from the relationships list. */
  openConnection: [string];
  vrchatSynced: [VrchatSummary];
  /** The Discord card looked this connection's IDs up: its connection id and the new profiles. */
  discordSynced: [string, DiscordProfile[]];
}>();

const { getRecordKey, vaultUnlocked } = useVault();

const name = ref('');
/**
 * Until the first decryption settles, the card shows the raw ciphertext (see
 * EncryptedText) — display only. `name`/`attributeValues` stay empty
 * until then, and editing is disabled, so the raw blob can never end up in the
 * edit form and be re-encrypted as if it were plaintext.
 */
const decrypted = ref(false);
const decryptFailed = ref(false);
const settled = computed(() => decrypted.value || decryptFailed.value);
const attributeValues = ref<Record<string, string>>({});

const editName = ref('');
const editSourceIds = ref<string[]>([...props.connection.source_ids]);
const editAttributeValues = ref<Record<string, string>>({});

// The page hands the sources over in their stored order; keep it.
const sortedSources = computed(() => props.sources);

function otherConnectionName(id: string): string {
  return props.connectionOptions.find((c) => c.id === id)?.label ?? '?';
}

/** This connection's own relationships — every edge touching its id, either direction. */
const myEdges = computed(() =>
  props.edges
    .filter((e) => e.from_connection_id === props.connection.id || e.to_connection_id === props.connection.id)
    .map((e) => ({
      ...e,
      otherId: e.from_connection_id === props.connection.id ? e.to_connection_id : e.from_connection_id,
    }))
    .map((e) => ({ ...e, otherName: otherConnectionName(e.otherId) }))
    .sort((a, b) => a.otherName.localeCompare(b.otherName)),
);

/** Picker options for connection-type fields — every other connection, self excluded. */
const relatableConnections = computed(() =>
  props.connectionOptions.filter((c) => c.id !== props.connection.id),
);

/** Picker options for "knows" — like the above, minus anyone already known (there's one edge per pair). */
const knowableConnections = computed(() => {
  const known = new Set(myEdges.value.map((e) => e.otherId));
  return relatableConnections.value.filter((c) => !known.has(c.id));
});

const newRelationTargetId = ref('');

/** Custom fields the owner asked to fill in on this card although they have no value yet (it remounts per connection, so this starts empty). */
const addedFieldIds = ref<string[]>([]);
const newFieldId = ref('');

/** A custom field shows once it has a value (saved or being typed); the rest wait behind the "add a field" picker. */
function hasValue(id: string): boolean {
  // Judged from the stored row, not the decrypted text, so a filled field is there from the first paint.
  const stored = props.connection.attribute_values.some(
    (v) => v.attribute_definition_id === id
      && (v.value_ciphertext || v.boolean_value != null || v.identifier_list_value?.length || v.identifier_value),
  );
  return stored || (editAttributeValues.value[id] ?? '') !== '';
}
const shownDefinitions = computed(() =>
  props.attributeDefinitions.filter((d) => addedFieldIds.value.includes(d.id) || hasValue(d.id)),
);
const addableFieldOptions = computed(() =>
  props.attributeDefinitions
    .filter((d) => !shownDefinitions.value.some((shown) => shown.id === d.id))
    .map((d) => ({ value: d.id, text: d.label, icon: ATTRIBUTE_TYPE_ICONS[d.type] })),
);

function addField(): void {
  if (!newFieldId.value) return;
  addedFieldIds.value.push(newFieldId.value);
  newFieldId.value = '';
}

/** Linked accounts with a name, as the free page can show them (keys match ShareLinkIdentity). */
const identityOptions = computed<IdentityOption[]>(() => [
  ...(props.discordProfiles ?? [])
    .filter((p) => p.known && (p.global_name || p.username))
    .map((p) => ({ key: `discord:${p.discord_user_id}`, label: `Discord: ${p.global_name || p.username}` })),
  ...(props.vrchatProfiles ?? [])
    .filter((p) => p.display_name)
    .map((p) => ({ key: `vrchat:${p.vrchat_user_id}`, label: `VRChat: ${p.display_name}` })),
]);

function onShareLinkChanged(shareLink: ShareLinkInfo | null): void {
  emit('updated', { ...props.connection, share_link: shareLink });
}

/** Asks first, naming both connections, then hands the removal to the page. */
async function removeRelationship(edge: { id: string; otherName: string }): Promise<void> {
  if (!(await requestConfirm(removalPrompt(name.value, edge.otherName)))) return;

  emit('removeEdge', edge.id);
}

/** Switches the page to the other connection's card; asks first if this one has unsaved edits, since they'd be dropped. */
async function openRelationship(edge: { otherId: string; otherName: string }): Promise<void> {
  if (
    isDirty.value &&
    !(await requestConfirm({
      title: 'Discard unsaved changes?',
      message: `You have unsaved changes to “${name.value}”. Opening “${edge.otherName}” will discard them.`,
      confirmText: 'Discard',
      variant: 'danger',
    }))
  ) {
    return;
  }

  emit('openConnection', edge.otherId);
}

function addRelationship(): void {
  if (!newRelationTargetId.value) return;
  emit('addEdge', props.connection.id, newRelationTargetId.value);
  newRelationTargetId.value = '';
}

async function decryptAll(): Promise<void> {
  // The card can be on screen before the unlock (a reload with this record in the URL). Not being able to
  // decrypt *yet* is not a failure: stay pending, and onVaultUnlocked below runs this again once the keys exist.
  if (!vaultUnlocked.value) return;

  // Whether the form was untouched before this decryption changes the saved state under it.
  const formWasClean = !isDirty.value;

  try {
    const key = await getRecordKey(props.connection.id);
    name.value = await decryptString(key, props.connection.name_ciphertext);

    const values: Record<string, string> = {};
    const listIds = new Set(props.attributeDefinitions.filter((d) => d.type === 'list' || d.type === 'link_list').map((d) => d.id));
    for (const attributeValue of props.connection.attribute_values) {
      // Lists are edited as one entry per line, so they ride in the same string map as every other value.
      // Booleans ride in the same string map as 'true'/'false' ('' = unset).
      // A list's text carries blank padding (see encryptedList.ts); the form shows just the entries.
      values[attributeValue.attribute_definition_id] = attributeValue.value_ciphertext
        ? (listIds.has(attributeValue.attribute_definition_id)
          ? listText(await decryptString(key, attributeValue.value_ciphertext))
          : await decryptString(key, attributeValue.value_ciphertext))
        : attributeValue.boolean_value != null
          ? String(attributeValue.boolean_value)
          : attributeValue.identifier_list_value
          ? attributeValue.identifier_list_value.join('\n')
          : (attributeValue.identifier_value ?? '');
    }
    attributeValues.value = values;
    decrypted.value = true;
    decryptFailed.value = false;
    if (formWasClean) populateEditForm();
  } catch (error) {
    // Locked mid-way is "not yet", not "broken": the next unlock decrypts again.
    if (error instanceof VaultLockedError) return;

    console.error(error);
    name.value = '(could not decrypt)';
    decryptFailed.value = true;
  }
}

/** A connection-type value is the referenced connection's id; null when it points at a connection that no longer exists. */
function connectionRefLabel(id: string): string | null {
  return props.connectionOptions.find((c) => c.id === id)?.label ?? null;
}

// Dates use a plain text field (ISO YYYY-MM-DD) — the native date picker is awkward to type into.
const INPUT_TYPE: Record<string, InputType> = { phone: 'tel', date: 'text' };
function inputType(type: string): InputType {
  return INPUT_TYPE[type] ?? (type as InputType);
}

/**
 * Copies the saved state into the form. If decryption hasn't finished yet the
 * encrypted values are still empty, and the EncryptedInput fields show the raw
 * ciphertext (disabled) in the meantime; they are filled in as soon as
 * decryption completes (see decryptAll).
 */
function populateEditForm(): void {
  editSourceIds.value = [...props.connection.source_ids];
  editName.value = name.value;
  editAttributeValues.value = { ...attributeValues.value };
  // Drop dangling connection references so saving clears them instead of resurrecting them.
  for (const definition of props.attributeDefinitions) {
    const value = editAttributeValues.value[definition.id];
    if (definition.type === 'connection' && value && !connectionRefLabel(value)) {
      editAttributeValues.value[definition.id] = '';
    }
  }
}

/** True when the form holds changes that haven't been saved. */
const isDirty = computed(() => {
  if (editName.value !== name.value) return true;

  const savedSources = [...props.connection.source_ids].sort();
  const draftSources = [...editSourceIds.value].sort();
  if (savedSources.length !== draftSources.length || savedSources.some((id, k) => id !== draftSources[k])) return true;

  const ids = new Set([...Object.keys(attributeValues.value), ...Object.keys(editAttributeValues.value)]);
  return [...ids].some((id) => (editAttributeValues.value[id] ?? '') !== (attributeValues.value[id] ?? ''));
});

// These must come AFTER everything decryptAll reads (isDirty above in particular): with the vault already
// unlocked — switching from one connection to another — onVaultUnlocked runs decryptAll immediately, during
// setup, and reading a const that is declared further down throws "can't access lexical declaration".
onVaultUnlocked(vaultUnlocked, decryptAll);
// The parent can replace this row from outside (e.g. the bulk identifier copy).
watch(() => props.connection.attribute_values, decryptAll);

/** v-model for the Connection dropdown: an unset field reads as '' so "(none)" is selected (see bindField). */
function fieldBinding(definitionId: string) {
  return bindField(editAttributeValues.value, definitionId);
}

/** A choice field's options, led by "(unset)" (the empty value). */
function radioChoices(options: readonly string[]) {
  return [{ value: '', text: '(unset)' }, ...options.map((option) => ({ value: option, text: option }))];
}

/** Country names are shown in the page language. */
const pageLocale = typeof document === 'undefined' ? undefined : document.documentElement.lang || undefined;

/** Yes/No is three radios, not a dropdown: fewer clicks for three options. */
const BOOLEAN_CHOICES = [
  { value: '', text: '(unset)' },
  { value: 'true', text: 'Yes' },
  { value: 'false', text: 'No' },
] as const;

/** The raw ciphertext of a field's stored value, shown (disabled) until it has been decrypted. */
function rawCiphertext(definitionId: string): string | null {
  return props.connection.attribute_values.find((v) => v.attribute_definition_id === definitionId)?.value_ciphertext ?? null;
}

/** True when every url-type field is empty or an http(s) link, and every line of a link list is one; Save is held back until then. */
const urlFieldsValid = computed(() =>
  props.attributeDefinitions.every((d) => {
    if (d.type === 'url') return isValidWebUrl(editAttributeValues.value[d.id]);
    if (d.type === 'link_list') return invalidLinkLines(editAttributeValues.value[d.id]).length === 0;

    return true;
  }),
);

/** A list field is edited as one entry per line. */
function splitLines(value: string | undefined): string[] {
  return (value ?? '').split('\n').map((v) => v.trim()).filter(Boolean);
}

async function save(): Promise<void> {
  // Belt and braces: the encrypted fields are disabled until decrypted anyway.
  if (!decrypted.value || !urlFieldsValid.value) return;

  try {
    const key = await getRecordKey(props.connection.id);

    const values: {
      attribute_definition_id: string;
      value_ciphertext?: string;
      identifier_value?: string;
      identifier_list_value?: string[];
      /** The list as this form loaded it: lets the server keep whatever it added since (see ListMerge). */
      identifier_list_base?: string[];
      boolean_value?: boolean;
    }[] = [];
    for (const definition of props.attributeDefinitions) {
      if (definition.type === 'identifier_list') {
        // §0.2 tier on purpose, same as identifier: plaintext on the wire, app-key encrypted at rest.
        // Always sent, even empty: the server merges against what it holds now, so tokens it added after
        // this page loaded (a VRChat name) aren't wiped by a save from a stale page.
        values.push({
          attribute_definition_id: definition.id,
          identifier_list_value: splitLines(editAttributeValues.value[definition.id]),
          identifier_list_base: splitLines(attributeValues.value[definition.id]),
        });
        continue;
      }

      const value = definition.type === 'url' ? editAttributeValues.value[definition.id]?.trim() : editAttributeValues.value[definition.id];
      if (!value) continue;
      if (definition.type === 'boolean') {
        // §0.2 tier on purpose, like identifier: plaintext on the wire, app-key encrypted at rest.
        values.push({ attribute_definition_id: definition.id, boolean_value: value === 'true' });
        continue;
      }
      if (definition.type === 'list' || definition.type === 'link_list') {
        // One ciphertext for the whole list, padded: the server learns neither the entries nor how many.
        const padded = paddedListText(value);
        if (padded !== '') values.push({ attribute_definition_id: definition.id, value_ciphertext: await encryptString(key, padded) });
        continue;
      }
      values.push(
        definition.type === 'identifier'
          ? { attribute_definition_id: definition.id, identifier_value: value }
          : { attribute_definition_id: definition.id, value_ciphertext: await encryptString(key, value) },
      );
    }

    const { data } = await axios.patch(`/dashboard/connections/${props.connection.id}`, {
      source_ids: editSourceIds.value,
      name_ciphertext: await encryptString(key, editName.value),
      attribute_values: values,
    });

    const renamed = editName.value !== name.value;
    name.value = editName.value;
    attributeValues.value = { ...editAttributeValues.value };
    emit('updated', data);
    if (renamed) emit('renamed', props.connection.id, editName.value);
  } catch (error) {
    console.error(error);
  }
}

const archiving = ref(false);

/**
 * Archiving applies immediately (it only hides the connection from the stats,
 * and archives its share link with it), so it is a button, not a form field
 * waiting on Save. The form never sends `archived`, so saving it can't undo this.
 */
async function toggleArchived(): Promise<void> {
  archiving.value = true;

  try {
    const { data } = await axios.patch(`/dashboard/connections/${props.connection.id}`, {
      archived: !props.connection.archived,
    });
    emit('updated', data);
  } catch (error) {
    console.error(error);
  } finally {
    archiving.value = false;
  }
}

async function remove(): Promise<void> {
  const confirmed = await requestConfirm({
    title: 'Delete this connection?',
    confirmText: 'Delete',
    variant: 'danger',
  });
  if (!confirmed) return;

  try {
    await axios.delete(`/dashboard/connections/${props.connection.id}`);
    emit('deleted', props.connection.id);
  } catch (error) {
    console.error(error);
  }
}
</script>

<template>
  <BCard :id="`connection-${connection.id}`" class="mb-3">
    <div class="d-flex justify-content-between align-items-start mb-3">
      <h3 class="h5 mb-0">
        <EncryptedText :value="settled ? name : undefined" :ciphertext="connection.name_ciphertext" />
        <BBadge v-if="connection.archived" variant="secondary" class="ms-1">Archived</BBadge>
      </h3>
      <div class="d-flex gap-2">
        <BButton
          variant="outline-secondary"
          size="sm"
          :disabled="archiving"
          :title="connection.archived ? 'Show this connection in the stats again' : 'Hide this connection from the stats'"
          @click="toggleArchived"
        >
          <AppIcon :icon="connection.archived ? ArchiveRestoreIcon : ArchiveIcon" />
          {{ connection.archived ? 'Unarchive' : 'Archive' }}
        </BButton>
        <BButton variant="outline-danger" size="sm" aria-label="Delete this connection" title="Delete" @click="remove">
          <AppIcon :icon="Trash2Icon" />
        </BButton>
      </div>
    </div>

    <div class="row">
      <div class="col-md-6">
        <BFormGroup label="Name" class="mb-3">
          <EncryptedInput v-model="editName" :ciphertext="connection.name_ciphertext" :pending="!decrypted" type="text" size="sm" />
        </BFormGroup>
      </div>
      <div class="col-md-6">
        <BFormGroup label="Sources" class="mb-3">
          <SearchableSelect
            v-model="editSourceIds"
            multiple
            placeholder="(none)"
            :options="sortedSources.map((source) => ({ value: source.id, text: source.label, swatch: source.color_key }))"
          />
        </BFormGroup>
      </div>
    </div>
    <BFormGroup label="Sharing" class="mb-3">
      <ConnectionSharing
        :connection-id="connection.id"
        :share-link="connection.share_link"
        :identities="identityOptions"
        @changed="onShareLinkChanged"
      />
    </BFormGroup>
    <BFormGroup v-if="vrchatProfiles?.length" label="VRChat" class="mb-3">
      <ConnectionVrchat
        :connection-id="connection.id"
        :profiles="vrchatProfiles"
        :synced-at="vrchatSyncedAt ?? null"
        @synced="emit('vrchatSynced', $event)"
      />
    </BFormGroup>
    <BFormGroup v-if="discordProfiles?.length" label="Discord" class="mb-3">
      <ConnectionDiscord
        :connection-id="connection.id"
        :profiles="discordProfiles"
        :synced-at="discordSyncedAt ?? null"
        :can-look-up="discordCanLookUp ?? false"
        @synced="emit('discordSynced', connection.id, $event)"
      />
    </BFormGroup>
    <!-- Everything above is built in; the fields below are the owner's own, so they get their own heading. -->
    <div v-if="attributeDefinitions.length" class="border-top pt-3 mt-4 mb-3">
      <h4 class="h6 text-uppercase text-body-secondary mb-0">Custom fields</h4>
    </div>
    <BFormGroup v-for="definition in shownDefinitions" :key="definition.id" :label="definition.label" class="mb-3">
      <EncryptedInput
        v-if="definition.type === 'textarea'"
        v-model="editAttributeValues[definition.id]"
        :ciphertext="rawCiphertext(definition.id)"
        :pending="!decrypted"
        multiline
        size="sm"
        rows="2"
      />
      <RadioChoices
        v-else-if="definition.type === 'radio'"
        v-model="editAttributeValues[definition.id]"
        :choices="radioChoices(definition.options)"
        :name="`field-${definition.id}`"
        :disabled="!decrypted"
      />
      <CountrySelect
        v-else-if="definition.type === 'country'"
        v-model="editAttributeValues[definition.id]"
        :locale="pageLocale"
        :disabled="!decrypted"
      />
      <SearchableSelect
        v-else-if="definition.type === 'connection'"
        v-bind="fieldBinding(definition.id)"
        :options="[{ value: '', text: '(none)' }, ...relatableConnections.map((c) => ({ value: c.id, text: c.label }))]"
        :disabled="!decrypted"
      />
      <RadioChoices
        v-else-if="definition.type === 'boolean'"
        v-model="editAttributeValues[definition.id]"
        :choices="BOOLEAN_CHOICES"
        :name="`field-${definition.id}`"
        :disabled="!decrypted"
      />
      <div v-else-if="definition.type === 'list'">
        <EncryptedInput
          v-model="editAttributeValues[definition.id]"
          :ciphertext="rawCiphertext(definition.id)"
          :pending="!decrypted"
          multiline
          size="sm"
          rows="3"
          placeholder="One entry per line"
          autocomplete="off"
        />
        <div class="form-text">End-to-end encrypted — one entry per line.</div>
      </div>
      <div v-else-if="definition.type === 'link_list'">
        <EncryptedInput
          v-model="editAttributeValues[definition.id]"
          :ciphertext="rawCiphertext(definition.id)"
          :pending="!decrypted"
          multiline
          size="sm"
          rows="3"
          placeholder="One https:// link per line"
          autocomplete="off"
          :state="invalidLinkLines(editAttributeValues[definition.id]).length === 0 ? null : false"
        />
        <div v-if="invalidLinkLines(editAttributeValues[definition.id]).length" class="text-danger small mt-1">
          Every line must be a full http:// or https:// link. Not a link: {{ invalidLinkLines(editAttributeValues[definition.id]).join(', ') }}
        </div>
        <ul v-if="validLinks(editAttributeValues[definition.id]).length" class="list-unstyled small mt-1 mb-0">
          <li v-for="href in validLinks(editAttributeValues[definition.id])" :key="href" class="text-truncate">
            <a :href="href" target="_blank" rel="noopener noreferrer" title="Open in a new tab">
              <AppIcon :icon="ExternalLinkIcon" /> {{ href }}
            </a>
          </li>
        </ul>
        <div class="form-text">End-to-end encrypted — one link per line.</div>
      </div>
      <div v-else-if="definition.type === 'identifier_list'">
        <EncryptedInput
          v-model="editAttributeValues[definition.id]"
          :ciphertext="rawCiphertext(definition.id)"
          :pending="!decrypted"
          multiline
          size="sm"
          rows="3"
          placeholder="One entry per line"
          autocomplete="off"
        />
        <div class="form-text">Not end-to-end encrypted — one entry per line.</div>
      </div>
      <BInputGroup v-else-if="definition.type === 'url'" size="sm">
        <EncryptedInput
          v-model="editAttributeValues[definition.id]"
          :ciphertext="rawCiphertext(definition.id)"
          :pending="!decrypted"
          type="url"
          size="sm"
          placeholder="https://"
          autocomplete="off"
          :state="isValidWebUrl(editAttributeValues[definition.id]) ? null : false"
        />
        <BButton
          variant="outline-secondary"
          size="sm"
          :href="parseWebUrl(editAttributeValues[definition.id]) ?? undefined"
          :disabled="!decrypted || parseWebUrl(editAttributeValues[definition.id]) === null"
          target="_blank"
          rel="noopener noreferrer"
          aria-label="Open in a new tab"
          title="Open in a new tab"
        >
          <AppIcon :icon="ExternalLinkIcon" />
        </BButton>
        <BFormInvalidFeedback v-if="!isValidWebUrl(editAttributeValues[definition.id])" force-show>Enter a full http:// or https:// link.</BFormInvalidFeedback>
      </BInputGroup>
      <EncryptedInput
        v-else-if="definition.type === 'identifier'"
        v-model="editAttributeValues[definition.id]"
        :ciphertext="rawCiphertext(definition.id)"
        :pending="!decrypted"
        type="text"
        size="sm"
        autocomplete="off"
      />
      <EncryptedInput
        v-else
        v-model="editAttributeValues[definition.id]"
        :ciphertext="rawCiphertext(definition.id)"
        :pending="!decrypted"
        :type="inputType(definition.type)"
        :placeholder="definition.type === 'date' ? 'YYYY-MM-DD' : undefined"
        :pattern="definition.type === 'date' ? '\\d{4}-\\d{2}-\\d{2}' : undefined"
        :title="definition.type === 'date' ? 'ISO date, e.g. 2026-11-06' : undefined"
        :inputmode="definition.type === 'date' ? 'numeric' : undefined"
        :autocomplete="definition.type === 'date' ? 'off' : undefined"
        size="sm"
      />
    </BFormGroup>
    <div v-if="addableFieldOptions.length" class="input-group input-group-sm mb-3">
      <SearchableSelect
        v-model="newFieldId"
        class="flex-grow-1"
        placeholder="Add a custom field…"
        aria-label="Custom field to add"
        :options="addableFieldOptions"
      />
      <BButton variant="outline-secondary" size="sm" :disabled="!newFieldId" @click="addField">Add field</BButton>
    </div>

    <BButton size="sm" variant="primary" :disabled="!decrypted || !isDirty || !urlFieldsValid" @click="save">Save</BButton>
    <BButton v-if="isDirty" size="sm" variant="outline-secondary" class="ms-2" @click="populateEditForm">Discard changes</BButton>

    <!-- A separate section from the form above: every change here saves on its own, immediately. -->
    <hr class="my-4" />
    <BFormGroup label="Relationships" description="Changes here save immediately." class="mb-0">
      <!-- w-auto keeps each button next to the name it acts on (not pushed to the far edge); the row highlights on hover. -->
      <table v-if="myEdges.length" class="table table-sm table-hover align-middle w-auto mb-2">
        <tbody>
          <tr v-for="edge in myEdges" :key="edge.id">
            <td class="pe-3">{{ edge.otherName }}</td>
            <td class="text-nowrap">
              <BButton
                size="sm"
                variant="outline-secondary"
                class="me-1"
                :aria-label="`Open ${edge.otherName}`"
                :title="`Open ${edge.otherName}`"
                @click="openRelationship(edge)"
              >
                <AppIcon :icon="ArrowRightIcon" /> Open
              </BButton>
              <BButton
                size="sm"
                variant="outline-danger"
                :aria-label="`Remove ${edge.otherName}`"
                :title="`Remove the link to ${edge.otherName}`"
                @click="removeRelationship(edge)"
              >
                <AppIcon :icon="UserMinusIcon" /> Remove
              </BButton>
            </td>
          </tr>
        </tbody>
      </table>
      <div class="input-group input-group-sm">
        <SearchableSelect
          v-model="newRelationTargetId"
          class="flex-grow-1"
          placeholder="Knows…"
          :options="knowableConnections.map((c) => ({ value: c.id, text: c.label }))"
        />
        <BButton variant="outline-secondary" size="sm" @click="addRelationship">Add</BButton>
      </div>
    </BFormGroup>
  </BCard>
</template>
