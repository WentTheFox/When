<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { BAlert, BBadge, BButton, BCard, BFormGroup, BFormInput, BFormSelect } from 'bootstrap-vue-next';
import { computed, ref, watch } from 'vue';
import EncryptedInput from '../Components/EncryptedInput.vue';
import EncryptedText from '../Components/EncryptedText.vue';
import { requestConfirm } from './confirmModal';
import LockedOverlay from './LockedOverlay.vue';

const TYPE_LABELS: Record<string, string> = {
  text: 'Text',
  textarea: 'Multi-line',
  radio: 'Choice',
  date: 'Date',
  number: 'Number',
  url: 'URL',
  email: 'Email',
  phone: 'Phone',
  connection: 'Connection',
  identifier: 'Identifier (not E2EE)',
  identifier_list: 'Identifier list (not E2EE)',
  boolean: 'Yes/No (not E2EE)',
};

interface DefinitionItem {
  id: string;
  type: string;
  /** Raw E2EE label, shown until `label` has been decrypted. */
  label_ciphertext: string;
  label?: string;
  options: string[];
}

const props = defineProps<{
  definitions: DefinitionItem[];
  /** How many connections currently hold a value for each field (definition id to count). */
  usage: Record<string, number>;
  /** Fields that site features depend on (field id to feature labels); those can't be deleted. */
  fieldFeatures: Record<string, string[]>;
  /** Why the server last refused to delete a field, if it did. */
  removeError?: string | null;
  /** Null while idle; otherwise the bulk identifier copy's progress/result line. */
  copyStatus: string | null;
  copyBusy: boolean;
}>();
const emit = defineEmits<{
  add: [string, string, string[]];
  rename: [string, string];
  remove: [string];
  copyToIdentifier: [string, string];
  sweepOrphans: [];
}>();

/** Only decrypted fields can be picked, searched or used by the tools below. */
const decryptedDefinitions = computed(() =>
  props.definitions.filter((d): d is DefinitionItem & { label: string } => d.label !== undefined),
);
const textDefinitions = computed(() => decryptedDefinitions.value.filter((d) => d.type === 'text'));
const identifierDefinitions = computed(() => decryptedDefinitions.value.filter((d) => d.type === 'identifier'));
const hasConnectionDefinitions = computed(() => props.definitions.some((d) => d.type === 'connection'));
const copySourceId = ref('');
const copyTargetId = ref('');

const filter = ref('');
// Lists render in the order they were given (their stored order, see listOrder.ts); sorting by name here would reshuffle them as names decrypt.
const filteredDefinitions = computed(() => {
  const query = filter.value.trim().toLowerCase();
  if (!query) return props.definitions;
  return props.definitions.filter((d) => (d.label ?? '').toLowerCase().includes(query));
});

/** The open record; bound by the page (v-model:selected-id) so it can live in the URL hash. */
const selectedId = defineModel<string | null>('selectedId', { default: null });
const selected = computed(() => props.definitions.find((d) => d.id === selectedId.value) ?? null);
/** True until the open record's label has been decrypted (e.g. opened from a deep link before unlocking). */
const pending = computed(() => selected.value !== null && selected.value.label === undefined);
const editLabel = ref('');

function select(definition: DefinitionItem): void {
  selectedId.value = definition.id;
}

// Fill the field whenever the open record changes or its label does (arriving once decrypted, or a rename round-tripping back through props).
watch(selected, (current) => {
  editLabel.value = current?.label ?? '';
}, { immediate: true, deep: true });

/** The site features that currently use a field (empty when it's free to delete). */
function featuresOf(id: string): string[] {
  return props.fieldFeatures[id] ?? [];
}

const renameUnchanged = computed(() => editLabel.value.trim() === (selected.value?.label ?? ''));

function saveRename(): void {
  const label = editLabel.value.trim();
  if (!selected.value || !label || renameUnchanged.value || pending.value) return;
  emit('rename', selected.value.id, label);
}

async function confirmRemove(): Promise<void> {
  const definition = selected.value;
  if (!definition || definition.label === undefined) return;

  // Backstop for the disabled button; the server refuses too.
  if (featuresOf(definition.id).length) return;

  const used = props.usage[definition.id] ?? 0;

  const parts = [
    used > 0
      ? `${used} connection${used === 1 ? ' holds' : 's hold'} a value for it. Those values are permanently deleted.`
      : 'No connection holds a value for it.',
    'This cannot be undone.',
  ];

  const confirmed = await requestConfirm({
    title: `Delete the “${definition.label}” field?`,
    message: parts.join(' '),
    confirmText: 'Delete field',
    variant: 'danger',
  });
  if (!confirmed) return;

  emit('remove', definition.id);
  selectedId.value = null;
}

const showNewForm = ref(false);
const newLabel = ref('');
const newType = ref('text');
const newChoices = ref('');

function toggleNewForm(): void {
  showNewForm.value = !showNewForm.value;
  if (showNewForm.value && !newLabel.value) newLabel.value = filter.value.trim();
}

function add(): void {
  if (!newLabel.value.trim()) return;
  const choices = newType.value === 'radio'
    ? newChoices.value.split(',').map((c) => c.trim()).filter((c) => c !== '')
    : [];
  emit('add', newLabel.value.trim(), newType.value, choices);
  newLabel.value = '';
  newChoices.value = '';
  filter.value = '';
  showNewForm.value = false;
}
</script>

<template>
  <div>
    <div class="row">
      <div class="col-md-4">
        <LockedOverlay>
          <div class="input-group input-group-sm mb-2">
            <BFormInput v-model="filter" type="text" placeholder="Search or new attribute" />
            <BButton variant="outline-secondary" @click="toggleNewForm">New</BButton>
          </div>

          <BCard v-if="showNewForm" class="mb-2" body-class="p-2">
            <BFormGroup label="Label" label-class="small" class="mb-2">
              <BFormInput v-model="newLabel" type="text" size="sm" @keyup.enter="add" />
            </BFormGroup>
            <BFormGroup label="Type" label-class="small" class="mb-2">
              <BFormSelect v-model="newType" size="sm">
                <option v-for="(label, type) in TYPE_LABELS" :key="type" :value="type">{{ label }}</option>
              </BFormSelect>
            </BFormGroup>
            <BFormGroup v-if="newType === 'radio'" label="Choices" label-class="small" description="Comma-separated." class="mb-2">
              <BFormInput v-model="newChoices" type="text" size="sm" @keyup.enter="add" />
            </BFormGroup>
            <p v-if="newType === 'identifier' || newType === 'identifier_list' || newType === 'boolean'" class="small text-muted mb-2">
              {{ newType === 'identifier_list' ? 'Identifier list' : newType === 'boolean' ? 'Yes/No' : 'Identifier' }} values are <strong>not</strong> end-to-end encrypted: they're sent to the server in plaintext
              (and encrypted at rest with the app key) so they can be used to link a connection to an external service<template v-if="newType === 'identifier_list'">,
              or as the highlight tokens of the connection's share link (choose the field in Settings)</template><template v-if="newType === 'boolean'">,
              or as the "bypass do not disturb" switch of the connection's share link (choose the field in Settings)</template>.
            </p>
            <BButton size="sm" variant="primary" :disabled="!newLabel.trim()" @click="add">Create</BButton>
          </BCard>

          <p v-if="filteredDefinitions.length === 0" class="text-muted small">No attributes found.</p>
          <div v-else class="list-group wtf-master-list">
            <button
              v-for="definition in filteredDefinitions"
              :key="definition.id"
              type="button"
              class="list-group-item list-group-item-action"
              :class="{ active: selectedId === definition.id }"
              @click="select(definition)"
            >
              <EncryptedText :value="definition.label" :ciphertext="definition.label_ciphertext" />
              <span class="small ms-1" :class="selectedId === definition.id ? '' : 'text-muted'">
                ({{ TYPE_LABELS[definition.type] ?? definition.type }})
              </span>
            </button>
          </div>
        </LockedOverlay>
      </div>

      <div class="col-md-8">
        <LockedOverlay v-if="selected">
          <BFormGroup label="Name" class="mb-3">
            <EncryptedInput
              v-model="editLabel"
              :ciphertext="selected.label_ciphertext"
              :pending="pending"
              type="text"
              size="sm"
              @keyup.enter="saveRename"
            />
          </BFormGroup>
          <dl class="row small mb-3">
            <dt class="col-sm-3">Type</dt>
            <dd class="col-sm-9">{{ TYPE_LABELS[selected.type] ?? selected.type }} <span class="text-muted">(fixed once created)</span></dd>
            <template v-if="selected.type === 'radio'">
              <dt class="col-sm-3">Choices</dt>
              <dd class="col-sm-9">{{ selected.options.join(', ') }}</dd>
            </template>
            <dt class="col-sm-3">Used on</dt>
            <dd class="col-sm-9">{{ usage[selected.id] ?? 0 }} connection{{ (usage[selected.id] ?? 0) === 1 ? '' : 's' }}</dd>
            <template v-if="featuresOf(selected.id).length">
              <dt class="col-sm-3">Used by</dt>
              <dd class="col-sm-9">
                <BBadge v-for="feature in featuresOf(selected.id)" :key="feature" variant="info" class="me-1">{{ feature }}</BBadge>
              </dd>
            </template>
          </dl>
          <BAlert :model-value="featuresOf(selected.id).length > 0" variant="info" class="small py-2">
            This field can't be deleted while a site feature uses it. Choose a different field (or none) on the
            <Link href="/settings#share-links">Settings page</Link> first.
          </BAlert>
          <BAlert :model-value="!!removeError && featuresOf(selected.id).length === 0" variant="danger" class="small py-2">
            {{ removeError }}
          </BAlert>
          <BButton size="sm" variant="primary" :disabled="pending || !editLabel.trim() || renameUnchanged" @click="saveRename">Save</BButton>
          <BButton
            size="sm"
            variant="outline-danger"
            class="ms-2"
            :disabled="pending || featuresOf(selected.id).length > 0"
            :title="featuresOf(selected.id).length ? 'In use by a site feature — see the note above' : undefined"
            @click="confirmRemove"
          >
            Delete
          </BButton>
        </LockedOverlay>
        <p v-else class="text-muted">Select an attribute on the left to rename or delete it.</p>
      </div>
    </div>

    <div v-if="hasConnectionDefinitions" class="small mt-3">
      <BButton variant="outline-secondary" size="sm" :disabled="copyBusy" @click="emit('sweepOrphans')">
        Remove references to deleted connections
      </BButton>
      <span class="text-muted ms-1">Deleting a connection already does this; use it if a reference was left behind.</span>
    </div>

    <template v-if="textDefinitions.length && identifierDefinitions.length">
      <hr>
      <h3 class="h6">Copy a text field into an identifier</h3>
      <p class="small text-muted mb-2">
        Decrypts the chosen text field in your browser and sends each value to the server in plaintext, for every
        connection whose identifier is still empty. Existing identifiers are never overwritten, so it's safe to re-run.
      </p>
      <div class="input-group input-group-sm mb-1">
        <BFormSelect v-model="copySourceId">
          <option value="" disabled>From (text)…</option>
          <option v-for="d in textDefinitions" :key="d.id" :value="d.id">{{ d.label }}</option>
        </BFormSelect>
        <BFormSelect v-model="copyTargetId">
          <option value="" disabled>To (identifier)…</option>
          <option v-for="d in identifierDefinitions" :key="d.id" :value="d.id">{{ d.label }}</option>
        </BFormSelect>
        <BButton
          variant="outline-secondary"
          :disabled="copyBusy || !copySourceId || !copyTargetId"
          @click="emit('copyToIdentifier', copySourceId, copyTargetId)"
        >
          Copy
        </BButton>
      </div>
      <div v-if="copyStatus" class="small text-muted">{{ copyStatus }}</div>
    </template>
  </div>
</template>
