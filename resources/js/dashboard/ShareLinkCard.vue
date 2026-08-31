<script setup lang="ts">
import axios from 'axios';
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome';
import { faCheck, faCopy } from '@fortawesome/free-solid-svg-icons';
import { BBadge, BButton, BCard, BFormCheckbox, BFormGroup, BFormInput, BFormSelect, BFormTextarea } from 'bootstrap-vue-next';
import { computed, onMounted, ref, watch } from 'vue';
import { decryptString, encryptString } from '../crypto';
import { useVault } from './useVault';

export interface ShareLinkRow {
  id: string;
  label_ciphertext: string | null;
  key_protection: 'fragment' | 'passphrase';
  archived: boolean;
  bypass_dnd: boolean;
  show_activity: boolean;
  legacy_token: string | null;
  connection_id: string | null;
  highlight_words: string[];
}

export interface ConnectionOption {
  id: string;
  name_ciphertext: string;
}

const props = defineProps<{ link: ShareLinkRow; connections: ConnectionOption[] }>();
const emit = defineEmits<{ updated: [ShareLinkRow]; regenerated: [ShareLinkRow, string] }>();

const { getRecordKey, vaultUnlocked } = useVault();

const label = ref('');
const editing = ref(false);
const editLabel = ref('');
const editBypassDnd = ref(props.link.bypass_dnd);
const editShowActivity = ref(props.link.show_activity);
const editWords = ref(props.link.highlight_words.join('\n'));

// Populated once the vault unlocks — both this card's own connection
// names (for the "tie to a connection" picker) and, if the link is
// already tied to one, used as a fallback label below.
const decryptedConnectionNames = ref<Record<string, string>>({});
const selectedConnectionId = ref(props.link.connection_id ?? '');
const savingConnection = ref(false);

async function decryptConnectionNames(): Promise<void> {
  for (const connection of props.connections) {
    if (decryptedConnectionNames.value[connection.id]) continue;
    try {
      const key = await getRecordKey(connection.id);
      decryptedConnectionNames.value[connection.id] = await decryptString(key, connection.name_ciphertext);
    } catch (error) {
      console.error(error);
      decryptedConnectionNames.value[connection.id] = '(could not decrypt)';
    }
  }
}

watch(vaultUnlocked, (unlocked) => {
  if (unlocked) decryptConnectionNames();
}, { immediate: true });

const connectionOptions = computed(() => [
  { value: '', text: '(none)' },
  ...props.connections.map((c) => ({ value: c.id, text: decryptedConnectionNames.value[c.id] ?? '…' })),
]);

async function onConnectionChange(): Promise<void> {
  const previousId = props.link.connection_id;
  const newId = selectedConnectionId.value || null;

  if (newId === previousId) return;

  savingConnection.value = true;

  try {
    if (previousId && previousId !== newId) {
      // Untie the connection that used to point at this link first — a
      // connection only ever points at one link, so leaving the old one
      // set would just make it silently point at nothing meaningful the
      // next time this card re-derives its "linked connection" display.
      await axios.patch(`/dashboard/connections/${previousId}`, { share_link_id: null });
    }

    if (newId) {
      await axios.patch(`/dashboard/connections/${newId}`, { share_link_id: props.link.id });
    }

    emit('updated', { ...props.link, connection_id: newId });
  } catch (error) {
    console.error(error);
    selectedConnectionId.value = previousId ?? '';
  } finally {
    savingConnection.value = false;
  }
}

/** Only meaningful once the vault's unlocked and decryptConnectionNames() has run — same "(no label)"-style placeholder pattern as label itself below until then. */
const linkedConnectionName = computed(() => {
  if (!props.link.connection_id) return null;
  return decryptedConnectionNames.value[props.link.connection_id] ?? '…';
});

/**
 * What the card actually shows — falls back to the tied connection's name
 * when there's no explicit label, rather than a bare "(no label)". Kept
 * separate from `label` itself (which stays the real, possibly-"(no
 * label)"-sentinel value): startEdit() below needs to tell "there really
 * is no label" apart from "showing a connection's name as a stand-in,"
 * otherwise editing would pre-fill the edit box with a connection's name
 * as if it were the link's own saved label.
 */
const displayLabel = computed(() => {
  if (label.value !== '(no label)') return label.value;
  return linkedConnectionName.value ?? label.value;
});

/**
 * A passphrase-protected or legacy link's URL carries no secret at all
 * (the passphrase supplies the key on the viewer's side; a legacy link's
 * key is derived straight from its token — see LegacyShareLinkKey) — the
 * path alone, built the same hardcoded `/free/{token}` way createLink()/
 * regenerateKey() already do (no Ziggy in this app), is the whole URL, no
 * server round trip needed. A real, non-legacy fragment-protected link is
 * the one case that does still need one: its key only ever existed
 * client-side for the moment it was generated (createLink()/
 * regenerateKey() build that URL locally, right then) — reloading this
 * card later has no local copy of it, only the server's
 * content_key_ciphertext, so revealing it again means asking the server
 * to decrypt it.
 */
const url = ref<string | null>(null);

async function loadUrl(): Promise<void> {
  const token = props.link.legacy_token ?? props.link.id;

  if (props.link.legacy_token || props.link.key_protection === 'passphrase') {
    url.value = `${window.location.origin}/free/${token}`;
    return;
  }

  try {
    const { data } = await axios.get(`/dashboard/share-links/${props.link.id}/url`);
    url.value = data.url as string;
  } catch (error) {
    console.error(error);
  }
}

onMounted(async () => {
  loadUrl();

  if (!props.link.label_ciphertext) {
    label.value = '(no label)';
    return;
  }
  try {
    const key = await getRecordKey(props.link.id);
    label.value = await decryptString(key, props.link.label_ciphertext);
  } catch (error) {
    console.error(error);
    label.value = '(could not decrypt)';
  }
});

function startEdit(): void {
  editLabel.value = label.value === '(no label)' ? '' : label.value;
  editing.value = true;
}

async function save(): Promise<void> {
  try {
    let labelCiphertext: string | undefined;
    if (editLabel.value) {
      const key = await getRecordKey(props.link.id);
      labelCiphertext = await encryptString(key, editLabel.value);
    }

    const { data } = await axios.patch(`/dashboard/share-links/${props.link.id}`, {
      label_ciphertext: labelCiphertext,
      bypass_dnd: editBypassDnd.value,
      show_activity: editShowActivity.value,
      highlight_words: editWords.value.split('\n').map((w) => w.trim()).filter(Boolean),
    });

    label.value = editLabel.value || '(no label)';
    editing.value = false;
    emit('updated', data);
  } catch (error) {
    console.error(error);
  }
}

async function toggleArchive(): Promise<void> {
  try {
    const { data } = await axios.patch(`/dashboard/share-links/${props.link.id}`, {
      archived: !props.link.archived,
    });
    emit('updated', data);
  } catch (error) {
    console.error(error);
  }
}

const justCopied = ref(false);

async function copyUrl(): Promise<void> {
  if (!url.value) return;

  await navigator.clipboard.writeText(url.value);
  justCopied.value = true;
  setTimeout(() => { justCopied.value = false; }, 1500);
}

async function regenerateKey(): Promise<void> {
  if (!window.confirm('This invalidates the existing link immediately. Continue?')) {
    return;
  }

  try {
    const { generateFragmentKey, buildFragment, exportAesKey, bytesToBase64 } = await import('../crypto');
    const { key, encoded } = await generateFragmentKey();
    const rawKey = await exportAesKey(key);

    const { data } = await axios.post(`/dashboard/share-links/${props.link.id}/regenerate-key`, {
      content_key: bytesToBase64(rawKey),
      key_protection: 'fragment',
    });

    const regeneratedUrl = `${window.location.origin}/free/${props.link.id}#${buildFragment(encoded)}`;
    url.value = regeneratedUrl;
    emit('regenerated', data, regeneratedUrl);
  } catch (error) {
    console.error(error);
  }
}

const badgeVariant = computed(() => (props.link.archived ? 'secondary' : 'info'));
</script>

<template>
  <BCard class="mb-3">
    <div class="d-flex justify-content-between align-items-start flex-wrap" style="gap: 0.5rem;">
      <div>
        <span>{{ displayLabel }}</span>
        <BBadge v-if="link.archived" variant="secondary" class="ms-2">Archived</BBadge>
        <BBadge :variant="badgeVariant" class="ms-2">{{ link.key_protection }}</BBadge>
        <BBadge v-if="link.legacy_token" variant="light" class="ms-2" title="Its key is derived from the token itself, not a separate fragment secret — it can't be rotated with Regenerate key.">token link</BBadge>
      </div>
      <div>
        <BButton variant="outline-secondary" size="sm" title="Copy link" @click="copyUrl">
          <FontAwesomeIcon :icon="justCopied ? faCheck : faCopy" />
        </BButton>
        <BButton variant="outline-secondary" size="sm" @click="startEdit">Edit</BButton>
        <BButton v-if="!link.legacy_token" variant="outline-warning" size="sm" @click="regenerateKey">
          Regenerate key
        </BButton>
        <BButton variant="outline-danger" size="sm" @click="toggleArchive">
          {{ link.archived ? 'Unarchive' : 'Archive' }}
        </BButton>
      </div>
    </div>

    <a v-if="url" :href="url" target="_blank" rel="noopener" class="d-block small font-monospace text-break mt-1">
      {{ url }}
    </a>

    <BFormGroup label="Tied to connection" class="mt-2 mb-0" style="max-width: 20rem;">
      <BFormSelect
        v-model="selectedConnectionId"
        size="sm"
        :options="connectionOptions"
        :disabled="savingConnection"
        @change="onConnectionChange"
      />
    </BFormGroup>

    <div v-if="editing" class="mt-3">
      <BFormGroup label="Label" class="mb-3">
        <BFormInput v-model="editLabel" type="text" size="sm" />
      </BFormGroup>
      <BFormCheckbox :id="`bypass-${link.id}`" v-model="editBypassDnd" class="mb-2">Bypass DND for this link</BFormCheckbox>
      <BFormCheckbox :id="`show-activity-${link.id}`" v-model="editShowActivity" class="mb-2">
        Show the activity name (e.g. "Dinner") on highlighted events, not just who it's with
      </BFormCheckbox>
      <BFormGroup label="Highlight words (one per line)" class="mb-3">
        <BFormTextarea v-model="editWords" size="sm" rows="2" />
      </BFormGroup>
      <BButton size="sm" variant="primary" @click="save">Save</BButton>
    </div>
  </BCard>
</template>
