<script setup lang="ts">
/**
 * Dashboard-wide "jump to a connection" search, mounted once in
 * SiteHeader.vue. Connection names are E2EE (§0.1) — this never sees
 * plaintext until the vault is unlocked, so focusing the box is what
 * triggers the unlock prompt (VaultGate.vue's own "compact" gate does the
 * same thing on first interaction, not on mount) rather than gating the
 * whole box behind a page-level VaultGate.
 */
import { router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref, watch } from 'vue';
import { decryptString } from '../crypto';
import { requestUnlock } from './vaultModal';
import { useVault } from './useVault';

interface IndexRow { id: string; name_ciphertext: string }

const page = usePage();
const { vaultUnlocked, getRecordKey } = useVault();

const query = ref('');
const open = ref(false);
const loading = ref(false);
const index = ref<{ id: string; name: string }[]>([]);
const loaded = ref(false);

// A lock while the box is open/mid-search invalidates whatever was
// decrypted under the old key — re-fetch and re-decrypt from scratch next
// time it's actually needed, rather than serving stale names.
watch(vaultUnlocked, (unlocked) => {
  if (!unlocked) loaded.value = false;
});

async function loadIndex(): Promise<void> {
  if (loaded.value || loading.value) return;

  loading.value = true;

  try {
    const { data: rows } = await axios.get<IndexRow[]>('/dashboard/connections/search-index');
    const decrypted: { id: string; name: string }[] = [];

    for (const row of rows) {
      try {
        const key = await getRecordKey(row.id);
        decrypted.push({ id: row.id, name: await decryptString(key, row.name_ciphertext) });
      } catch (error) {
        console.error(error);
      }
    }

    index.value = decrypted;
    loaded.value = true;
  } finally {
    loading.value = false;
  }
}

async function onFocus(): Promise<void> {
  const unlocked = await requestUnlock();
  if (!unlocked) return;

  open.value = true;
  await loadIndex();
}

const results = computed(() => {
  const needle = query.value.trim().toLowerCase();
  if (!needle) return [];

  return index.value
    .filter((row) => row.name.toLowerCase().includes(needle))
    .sort((a, b) => a.name.localeCompare(b.name))
    .slice(0, 8);
});

function goTo(id: string): void {
  query.value = '';
  open.value = false;

  const anchor = `connection-${id}`;

  if (page.url.startsWith('/dashboard/connections')) {
    document.getElementById(anchor)?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    return;
  }

  // No onSuccess/nextTick scroll here — from this persistent header
  // component, that raced the actual page swap (nextTick only waits for a
  // Vue update already scheduled at the moment it's called, and the new
  // page's render often hadn't been queued yet when onSuccess fired, so
  // the scroll ran against the still-outgoing page and found nothing).
  // The hash instead lets Connections.vue itself scroll on its own
  // onMounted, which Vue guarantees fires only once its own DOM exists.
  router.visit(`/dashboard/connections#${anchor}`);
}

// Closing on blur would also swallow the click on a result (blur fires
// before click), so close on the next tick instead — long enough for the
// click handler above to have already run.
function onBlur(): void {
  setTimeout(() => { open.value = false; }, 150);
}
</script>

<template>
  <div class="position-relative">
    <input
      v-model="query"
      type="search"
      class="form-control form-control-sm"
      placeholder="Search connections…"
      style="width: 12rem;"
      @focus="onFocus"
      @blur="onBlur"
    >

    <div v-if="open && query.trim()" class="dropdown-menu show w-100 mt-1 shadow-sm" style="max-height: 16rem; overflow-y: auto;">
      <span v-if="loading" class="dropdown-item-text small text-muted">Decrypting…</span>
      <template v-else-if="results.length">
        <button
          v-for="result in results"
          :key="result.id"
          type="button"
          class="dropdown-item small"
          @click="goTo(result.id)"
        >
          {{ result.name }}
        </button>
      </template>
      <span v-else class="dropdown-item-text small text-muted">No matches.</span>
    </div>
  </div>
</template>
