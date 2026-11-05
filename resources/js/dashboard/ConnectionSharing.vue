<script setup lang="ts">
import axios from 'axios';
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome';
import { faCheck, faCopy, faRotateRight, faTrash } from '@fortawesome/free-solid-svg-icons';
import { BButton, BSpinner } from 'bootstrap-vue-next';
import { computed, ref } from 'vue';
import { requestConfirm } from './confirmModal';

/** What ConnectionController/ConnectionShareLinkController send for a connection's link (all plaintext tier, §0.2). */
export interface ShareLinkInfo {
  id: string;
  highlight_token: string | null;
  last_visited_at: string | null;
}

const props = defineProps<{ connectionId: string; shareLink: ShareLinkInfo | null }>();
const emit = defineEmits<{ changed: [ShareLinkInfo | null] }>();

const base = computed(() => `/dashboard/connections/${props.connectionId}/share-link`);

// A share link's URL carries no secret beyond the token itself: the content
// key derives from it (HighlightTokenKey), so the path is the whole URL.
const url = computed(() => (props.shareLink?.highlight_token
  ? `${window.location.origin}/free/${props.shareLink.highlight_token}`
  : null));

const busy = ref(false);

async function enable(): Promise<void> {
  busy.value = true;
  try {
    const { data } = await axios.post<ShareLinkInfo>(base.value);
    emit('changed', data);
  } catch (error) {
    console.error(error);
  } finally {
    busy.value = false;
  }
}

async function regenerate(): Promise<void> {
  const confirmed = await requestConfirm({
    title: 'Regenerate this link?',
    message: 'This invalidates the existing link immediately and issues a new one.',
    confirmText: 'Regenerate',
    variant: 'danger',
  });
  if (!confirmed) return;

  try {
    const { data } = await axios.post<ShareLinkInfo>(`${base.value}/regenerate-token`);
    emit('changed', data);
  } catch (error) {
    console.error(error);
  }
}

async function disable(): Promise<void> {
  const confirmed = await requestConfirm({
    title: 'Stop sharing with this connection?',
    message: 'The link and its visit history are permanently deleted. Enabling sharing again issues a different link.',
    confirmText: 'Delete link',
    variant: 'danger',
  });
  if (!confirmed) return;

  try {
    await axios.delete(base.value);
    visits.value = [];
    showVisits.value = false;
    emit('changed', null);
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

const showVisits = ref(false);
const visits = ref<VisitRow[]>([]);
const visitsPage = ref(1);
const visitsLastPage = ref(1);
const loadingVisits = ref(false);

async function fetchVisits(page: number): Promise<void> {
  loadingVisits.value = true;
  try {
    const { data } = await axios.get<VisitsPage>(`${base.value}/visits`, { params: { page } });
    visits.value = data.data;
    visitsPage.value = data.current_page;
    visitsLastPage.value = data.last_page;
  } catch (error) {
    console.error(error);
  } finally {
    loadingVisits.value = false;
  }
}

// Loaded on first expand, not on mount: most connections never open it.
function toggleVisits(): void {
  showVisits.value = !showVisits.value;
  if (showVisits.value) fetchVisits(1);
}

async function deleteVisit(visit: VisitRow): Promise<void> {
  try {
    await axios.delete(`${base.value}/visits/${visit.id}`);
    // Step back a page if that was the last row of a later page.
    await fetchVisits(visits.value.length === 1 && visitsPage.value > 1 ? visitsPage.value - 1 : visitsPage.value);
  } catch (error) {
    console.error(error);
  }
}

async function deleteAllVisits(): Promise<void> {
  const confirmed = await requestConfirm({
    title: 'Delete all visits?',
    message: 'Every recorded visit for this link will be permanently removed.',
    confirmText: 'Delete all',
    variant: 'danger',
  });
  if (!confirmed) return;
  try {
    await axios.delete(`${base.value}/visits`);
    await fetchVisits(1);
  } catch (error) {
    console.error(error);
  }
}
</script>

<template>
  <div>
    <div v-if="!shareLink">
      <p class="small text-muted mb-2">Not shared. Enabling sharing creates a free/busy link named after this connection.</p>
      <!-- Solid, not outline: a blue outline button has too little contrast on the dark theme. -->
      <BButton size="sm" variant="primary" :disabled="busy" @click="enable">Enable sharing</BButton>
    </div>

    <template v-else>
      <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
        <a v-if="url" :href="url" target="_blank" rel="noopener" class="small font-monospace text-break">{{ url }}</a>
        <div class="d-flex gap-2">
          <BButton variant="outline-secondary" size="sm" title="Copy link" @click="copyUrl">
            <FontAwesomeIcon :icon="justCopied ? faCheck : faCopy" />
          </BButton>
          <BButton variant="outline-warning" size="sm" @click="regenerate">Regenerate link</BButton>
          <BButton variant="outline-danger" size="sm" @click="disable">Stop sharing</BButton>
        </div>
      </div>
      <p class="small text-muted mt-2 mb-2">
        What this link highlights and whether it bypasses do-not-disturb come from this connection's fields
        (chosen on the Settings page). Archiving the connection only hides it from the dashboard stats; the link keeps working.
      </p>

      <BButton variant="link" size="sm" class="p-0" @click="toggleVisits">
        {{ showVisits ? 'Hide visit history' : 'Show visit history' }}
      </BButton>

      <div v-if="showVisits" class="mt-2">
        <div class="d-flex align-items-center justify-content-between mb-2">
          <BButton variant="outline-secondary" size="sm" title="Reload" :disabled="loadingVisits" @click="fetchVisits(visitsPage)">
            <FontAwesomeIcon :icon="faRotateRight" :spin="loadingVisits" />
          </BButton>
          <BButton v-if="visits.length" variant="outline-danger" size="sm" @click="deleteAllVisits">Delete all</BButton>
        </div>
        <BSpinner v-if="loadingVisits && !visits.length" small />
        <p v-else-if="!visits.length" class="small text-muted mb-0">No visits recorded yet.</p>
        <template v-else>
          <table class="table table-sm mb-2">
            <thead>
              <tr><th>When</th><th>Visitor timezone</th><th>Visitor locale</th><th></th></tr>
            </thead>
            <tbody>
              <tr v-for="visit in visits" :key="visit.id">
                <td>{{ new Date(visit.visited_at).toLocaleString() }}</td>
                <td>{{ visit.timezone }}</td>
                <td>{{ visit.locale ?? '—' }}</td>
                <td class="text-end">
                  <BButton variant="outline-danger" size="sm" title="Delete this visit" @click="deleteVisit(visit)">
                    <FontAwesomeIcon :icon="faTrash" />
                  </BButton>
                </td>
              </tr>
            </tbody>
          </table>
          <div v-if="visitsLastPage > 1" class="d-flex align-items-center gap-2">
            <BButton size="sm" variant="outline-secondary" :disabled="visitsPage <= 1" @click="fetchVisits(visitsPage - 1)">Previous</BButton>
            <span class="small text-muted">Page {{ visitsPage }} of {{ visitsLastPage }}</span>
            <BButton size="sm" variant="outline-secondary" :disabled="visitsPage >= visitsLastPage" @click="fetchVisits(visitsPage + 1)">Next</BButton>
          </div>
        </template>
      </div>
    </template>
  </div>
</template>
