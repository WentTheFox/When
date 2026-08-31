<script setup lang="ts">
import axios from 'axios';
import { BBadge, BButton, BCard, BFormCheckbox, BFormGroup, BFormInput, BFormTextarea } from 'bootstrap-vue-next';
import { computed, onMounted, ref } from 'vue';
import { decryptString, encryptString } from '../crypto';
import { useVault } from './useVault';

interface ManualTag {
  word: string;
  weekday: number | null;
  start_time: string;
  end_time: string;
}

export interface ShareLinkRow {
  id: string;
  label_ciphertext: string | null;
  key_protection: 'fragment' | 'passphrase';
  archived: boolean;
  bypass_dnd: boolean;
  show_activity: boolean;
  legacy_token: string | null;
  highlight_words: string[];
  manual_tags: ManualTag[];
}

const props = defineProps<{ link: ShareLinkRow }>();
const emit = defineEmits<{ updated: [ShareLinkRow]; regenerated: [ShareLinkRow, string] }>();

const { getRecordKey } = useVault();

const label = ref('');
const editing = ref(false);
const shownUrl = ref('');
const editLabel = ref('');
const editBypassDnd = ref(props.link.bypass_dnd);
const editShowActivity = ref(props.link.show_activity);
const editWords = ref(props.link.highlight_words.join('\n'));
const editTags = ref(
  props.link.manual_tags.map((t) => `${t.word},${t.weekday ?? ''},${t.start_time},${t.end_time}`).join('\n'),
);

onMounted(async () => {
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

function parseManualTags(text: string): ManualTag[] {
  return text
    .split('\n')
    .map((line) => line.trim())
    .filter(Boolean)
    .map((line) => {
      const [word, weekday, start, end] = line.split(',').map((part) => part.trim());
      return { word, weekday: weekday ? Number(weekday) : null, start_time: start, end_time: end };
    });
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
      manual_tags: parseManualTags(editTags.value),
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

async function showUrl(): Promise<void> {
  try {
    const { data } = await axios.get(`/dashboard/share-links/${props.link.id}/url`);
    shownUrl.value = `${window.location.origin}${data.url}`;
  } catch (error) {
    console.error(error);
  }
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

    const url = `${window.location.origin}/free/${props.link.id}#${buildFragment(encoded)}`;
    emit('regenerated', data, url);
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
        <span>{{ label }}</span>
        <BBadge v-if="link.archived" variant="secondary" class="ms-2">Archived</BBadge>
        <BBadge :variant="badgeVariant" class="ms-2">{{ link.key_protection }}</BBadge>
        <BBadge v-if="link.legacy_token" variant="light" class="ms-2">legacy</BBadge>
      </div>
      <div>
        <BButton variant="outline-secondary" size="sm" @click="showUrl">Show link</BButton>
        <BButton variant="outline-secondary" size="sm" @click="startEdit">Edit</BButton>
        <BButton v-if="!link.legacy_token" variant="outline-warning" size="sm" @click="regenerateKey">
          Regenerate key
        </BButton>
        <BButton variant="outline-danger" size="sm" @click="toggleArchive">
          {{ link.archived ? 'Unarchive' : 'Archive' }}
        </BButton>
      </div>
    </div>

    <div v-if="shownUrl" class="small font-monospace text-muted mt-2">{{ shownUrl }}</div>

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
      <BFormGroup
        label="Manual tags (word, weekday 0-6 or blank, start HH:MM, end HH:MM — one per line, comma-separated)"
        class="mb-3"
      >
        <BFormTextarea v-model="editTags" size="sm" rows="3" />
      </BFormGroup>
      <BButton size="sm" variant="primary" @click="save">Save</BButton>
    </div>
  </BCard>
</template>
