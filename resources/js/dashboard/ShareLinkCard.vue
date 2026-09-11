<script setup lang="ts">
import axios from 'axios';
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome';
import { faCheck, faCopy, faRotateRight } from '@fortawesome/free-solid-svg-icons';
import { BBadge, BButton, BCard, BFormCheckbox, BFormGroup, BFormInput, BFormSelect, BFormTextarea, BSpinner } from 'bootstrap-vue-next';
import { computed, onMounted, ref, watch } from 'vue';
import { decryptString, encryptString } from '../crypto';
import { requestConfirm } from './confirmModal';
import { useVault } from './useVault';

export interface ShareLinkRow {
  id: string;
  label_ciphertext: string | null;
  archived: boolean;
  bypass_dnd: boolean;
  show_activity: boolean;
  highlight_token: string | null;
  connection_id: string | null;
  highlight_words: string[];
}

export interface ConnectionOption {
  id: string;
  name_ciphertext: string;
}

const props = defineProps<{ link: ShareLinkRow; connections: ConnectionOption[] }>();
const emit = defineEmits<{ updated: [ShareLinkRow]; regenerated: [ShareLinkRow, string]; deleted: [string] }>();

const { getRecordKey, vaultUnlocked } = useVault();

const label = ref('');
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
  ...props.connections
    .map((c) => ({ value: c.id, text: decryptedConnectionNames.value[c.id] ?? '…' }))
    .sort((a, b) => a.text.localeCompare(b.text)),
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
 * What the card's header shows — falls back to the tied connection's name
 * when there's no explicit label, rather than a bare "(no label)". Kept
 * separate from `label` itself (which stays the real, possibly-"(no
 * label)"-sentinel value): onMounted below needs to tell "there really is
 * no label" apart from "showing a connection's name as a stand-in,"
 * otherwise the label field would get pre-filled with a connection's name
 * as if it were the link's own saved label.
 */
const displayLabel = computed(() => {
  if (label.value !== '(no label)') return label.value;
  return linkedConnectionName.value ?? label.value;
});

/**
 * A share link's URL carries no secret at all — every link's content key
 * derives deterministically from its own highlight_token (see
 * HighlightTokenKey), so the path alone (no fragment, no server round
 * trip) is the whole URL.
 */
const url = computed(() => `${window.location.origin}/free/${props.link.highlight_token}`);

onMounted(async () => {
  if (!props.link.label_ciphertext) {
    label.value = '(no label)';
  } else {
    try {
      const key = await getRecordKey(props.link.id);
      label.value = await decryptString(key, props.link.label_ciphertext);
      editLabel.value = label.value;
    } catch (error) {
      console.error(error);
      label.value = '(could not decrypt)';
    }
  }

  fetchVisits(1);
});

interface VisitRow {
  id: string;
  visited_at: string;
  timezone: string;
  locale: string | null;
}

interface VisitsPage {
  data: VisitRow[];
  current_page: number;
  last_page: number;
}

const visits = ref<VisitRow[]>([]);
const visitsPage = ref(1);
const visitsLastPage = ref(1);
const loadingVisits = ref(false);

async function fetchVisits(page: number): Promise<void> {
  loadingVisits.value = true;
  try {
    const { data } = await axios.get<VisitsPage>(`/dashboard/share-links/${props.link.id}/visits`, {
      params: { page },
    });
    visits.value = data.data;
    visitsPage.value = data.current_page;
    visitsLastPage.value = data.last_page;
  } catch (error) {
    console.error(error);
  } finally {
    loadingVisits.value = false;
  }
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

async function regenerateToken(): Promise<void> {
  const confirmed = await requestConfirm({
    title: 'Regenerate this link?',
    message: 'This invalidates the existing link immediately and issues a new one.',
    confirmText: 'Regenerate',
    variant: 'danger',
  });
  if (!confirmed) return;

  try {
    const { data } = await axios.post(`/dashboard/share-links/${props.link.id}/regenerate-token`);
    const regeneratedUrl = `${window.location.origin}/free/${data.highlight_token}`;
    emit('regenerated', data, regeneratedUrl);
  } catch (error) {
    console.error(error);
  }
}

async function remove(): Promise<void> {
  const confirmed = await requestConfirm({
    title: 'Permanently delete this share link?',
    message: 'This cannot be undone.',
    confirmText: 'Delete',
    variant: 'danger',
  });
  if (!confirmed) return;

  try {
    await axios.delete(`/dashboard/share-links/${props.link.id}`);
    emit('deleted', props.link.id);
  } catch (error) {
    console.error(error);
  }
}

</script>

<template>
  <BCard class="mb-3">
    <div class="d-flex justify-content-between align-items-start flex-wrap" style="gap: 0.5rem;">
      <div>
        <span>{{ displayLabel }}</span>
        <BBadge v-if="link.archived" variant="secondary" class="ms-2">Archived</BBadge>
      </div>
      <div>
        <BButton variant="outline-secondary" size="sm" title="Copy link" @click="copyUrl">
          <FontAwesomeIcon :icon="justCopied ? faCheck : faCopy" />
        </BButton>
        <BButton variant="outline-warning" size="sm" @click="regenerateToken">Regenerate link</BButton>
        <BButton variant="outline-danger" size="sm" @click="toggleArchive">
          {{ link.archived ? 'Unarchive' : 'Archive' }}
        </BButton>
        <BButton variant="outline-danger" size="sm" @click="remove">Delete</BButton>
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

    <div class="mt-3">
      <BFormGroup label="Label" class="mb-3">
        <BFormInput v-model="editLabel" type="text" size="sm" />
      </BFormGroup>
      <BFormCheckbox :id="`bypass-${link.id}`" v-model="editBypassDnd" class="mb-2">Bypass DND for this link</BFormCheckbox>
      <BFormCheckbox :id="`show-activity-${link.id}`" v-model="editShowActivity" class="mb-2">
        Show the activity name (e.g. "Dinner") on highlighted events, not just who it's with
      </BFormCheckbox>
      <BFormGroup label="Highlight words (one per line)" class="mb-3">
        <BFormTextarea v-model="editWords" size="sm" :rows="Math.max(2, editWords.split('\n').length)" />
      </BFormGroup>
      <BButton size="sm" variant="primary" @click="save">Save</BButton>
    </div>

    <div class="mt-4">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <h6 class="mb-0">Visit history</h6>
        <BButton variant="outline-secondary" size="sm" title="Reload" :disabled="loadingVisits" @click="fetchVisits(visitsPage)">
          <FontAwesomeIcon :icon="faRotateRight" :spin="loadingVisits" />
        </BButton>
      </div>
      <BSpinner v-if="loadingVisits && !visits.length" small />
      <p v-else-if="!visits.length" class="small text-muted mb-0">No visits recorded yet.</p>
      <template v-else>
        <table class="table table-sm mb-2">
          <thead>
            <tr>
              <th>When</th>
              <th>Visitor timezone</th>
              <th>Visitor locale</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="visit in visits" :key="visit.id">
              <td>{{ new Date(visit.visited_at).toLocaleString() }}</td>
              <td>{{ visit.timezone }}</td>
              <td>{{ visit.locale ?? '—' }}</td>
            </tr>
          </tbody>
        </table>
        <div v-if="visitsLastPage > 1" class="d-flex align-items-center gap-2">
          <BButton
            size="sm"
            variant="outline-secondary"
            :disabled="visitsPage <= 1"
            @click="fetchVisits(visitsPage - 1)"
          >
            Previous
          </BButton>
          <span class="small text-muted">Page {{ visitsPage }} of {{ visitsLastPage }}</span>
          <BButton
            size="sm"
            variant="outline-secondary"
            :disabled="visitsPage >= visitsLastPage"
            @click="fetchVisits(visitsPage + 1)"
          >
            Next
          </BButton>
        </div>
      </template>
    </div>
  </BCard>
</template>
