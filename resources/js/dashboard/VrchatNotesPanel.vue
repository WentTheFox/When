<script setup lang="ts">
/**
 * VRChat notes waiting to be carried over to connections' Notes field. The field is
 * end-to-end encrypted, so the server can't write it: this panel only
 * appears with something to import, and your browser does the writing, after you
 * press Import. Each note is added on a new line after any text already there.
 */
import { BBadge, BButton, BCard } from 'bootstrap-vue-next';
import { computed, ref } from 'vue';

export interface NoteImportItem {
  connectionId: string;
  /** The connection's decrypted name ('' while it can't be read). */
  name: string;
  notes: { vrchatId: string; note: string }[];
}

const props = defineProps<{
  items: NoteImportItem[];
  /** The vault is unlocked, so notes can be decrypted and re-encrypted. */
  ready: boolean;
  busy: boolean;
  /** "3 of 40" while importing. */
  progress: string;
}>();
const emit = defineEmits<{ import: [] }>();

const showAll = ref(false);
const PREVIEW = 5;
const visible = computed(() => (showAll.value ? props.items : props.items.slice(0, PREVIEW)));
const total = computed(() => props.items.reduce((n, item) => n + item.notes.length, 0));

function snippet(note: string): string {
  const flat = note.replace(/\s+/g, ' ');
  return flat.length > 90 ? `${flat.slice(0, 90)}…` : flat;
}
</script>

<template>
  <BCard id="vrchat-notes" class="mb-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
      <h2 class="h5 mb-0">
        VRChat notes to carry over
        <BBadge variant="secondary" pill class="ms-1">{{ total }}</BBadge>
      </h2>
      <BButton v-if="ready" variant="primary" size="sm" :disabled="busy" @click="emit('import')">
        {{ busy ? `Importing… ${progress}` : `Import ${total === 1 ? 'the note' : `all ${total} notes`}` }}
      </BButton>
    </div>
    <p class="small text-muted mb-2">
      Your private VRChat notes on {{ items.length === 1 ? 'a linked account' : `${items.length} connections' linked accounts` }}.
      Importing adds each note to the connection's Notes field on a new line, after anything already written there, and never
      adds the same text twice. Until then the server keeps the note (encrypted at rest with the app key, not end-to-end); afterwards it
      keeps only a fingerprint of it.
    </p>

    <p v-if="!ready" class="small mb-0">Unlock your vault to import: the Notes field is end-to-end encrypted.</p>

    <template v-else>
      <ul class="list-unstyled small mb-0">
        <li v-for="item in visible" :key="item.connectionId" class="mb-1">
          <strong>{{ item.name || '(unnamed)' }}</strong>
          <span v-for="n in item.notes" :key="n.vrchatId" class="text-muted d-block ms-3">“{{ snippet(n.note) }}”</span>
        </li>
      </ul>
      <BButton v-if="items.length > PREVIEW" variant="link" size="sm" class="p-0 mt-1" @click="showAll = !showAll">
        {{ showAll ? `Show the first ${PREVIEW}` : `Show all ${items.length}` }}
      </BButton>
    </template>
  </BCard>
</template>
