<script setup lang="ts">
import { LockIcon } from '@lucide/vue';
import AppIcon from '../Components/AppIcon.vue';
import { Link } from '@inertiajs/vue3';
import { BAlert, BBadge, BButton, BCard, BFormGroup, BFormInput } from 'bootstrap-vue-next';
import { computed, ref, watch } from 'vue';
import SearchableSelect from '../Components/SearchableSelect.vue';
import { ATTRIBUTE_TYPE_ICONS } from './attributeTypeIcons';
import EncryptedInput from '../Components/EncryptedInput.vue';
import EncryptedText from '../Components/EncryptedText.vue';
import { requestConfirm } from './confirmModal';
import { type MigrationPath, pathsFrom } from './fieldMigrations';
import MigrateFieldSection from './MigrateFieldSection.vue';
import IconRulesEditor from './IconRulesEditor.vue';
import type { IconRuleSet, ParsedRuleSet } from './iconRules';
import LockedOverlay from './LockedOverlay.vue';

const TYPE_LABELS: Record<string, string> = {
  text: 'Text',
  textarea: 'Multi-line',
  list: 'List',
  radio: 'Choice',
  country: 'Country',
  date: 'Date',
  number: 'Number',
  url: 'URL',
  link_list: 'Link list',
  email: 'Email',
  phone: 'Phone',
  connection: 'Connection',
  relationship: 'Relationship',
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
  /** Which field types each feature (by label) can use. */
  fieldFeatureTypes: Record<string, string[]>;
  /** Every type change a field may go through. */
  migrations: MigrationPath[];
  /** Why the server last refused to delete a field, if it did. */
  removeError?: string | null;
  /** Decrypted icon rules per field id (only fields that have some). */
  iconRuleSets: Record<string, ParsedRuleSet>;
  /** Null while idle; otherwise the bulk identifier copy's progress/result line. */
  copyStatus: string | null;
  copyBusy: boolean;
}>();
const emit = defineEmits<{
  add: [string, string, string[]];
  rename: [string, string];
  /** Choice field: the full new list (existing choices reordered, new ones appended). */
  updateOptions: [string, string[]];
  remove: [string];
  /** Icon rules: the field id and its full new rule set, to be encrypted and saved. */
  saveIconRules: [string, IconRuleSet];
  /** Migrate the field (id) to another type: the page converts every value, then asks the server to swap them in. */
  migrate: [string, string, { strategy?: string; releaseBindings?: boolean }];
  /** Create an identifier field with this name, then copy the text field (first id) into it. */
  createIdentifierAndCopy: [string, string];
  copyToIdentifier: [string, string];
  sweepOrphans: [];
}>();

/** Only decrypted fields can be picked, searched or used by the tools below. */
const decryptedDefinitions = computed(() =>
  props.definitions.filter((d): d is DefinitionItem & { label: string } => d.label !== undefined),
);
const identifierDefinitions = computed(() => decryptedDefinitions.value.filter((d) => d.type === 'identifier'));
const hasConnectionDefinitions = computed(() => props.definitions.some((d) => d.type === 'connection' || d.type === 'relationship'));

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

/** Choice editing is additive only: existing choices can be reordered but not edited or removed (values hold them verbatim). */
const editOptions = ref<string[]>([]);
const newOption = ref('');
watch(selected, (current) => {
  editOptions.value = [...(current?.options ?? [])];
  newOption.value = '';
}, { immediate: true, deep: true });

const optionsUnchanged = computed(() => {
  const original = selected.value?.options ?? [];
  return editOptions.value.length === original.length && editOptions.value.every((o, i) => o === original[i]);
});

function addOption(): void {
  const option = newOption.value.trim();
  if (!option || editOptions.value.includes(option)) return;
  editOptions.value.push(option);
  newOption.value = '';
}

/** Only options not yet saved can be dropped again; saved ones stay. */
function removeUnsavedOption(index: number): void {
  if ((selected.value?.options ?? []).includes(editOptions.value[index])) return;
  editOptions.value.splice(index, 1);
}

function moveOption(index: number, delta: number): void {
  const target = index + delta;
  if (target < 0 || target >= editOptions.value.length) return;
  const [item] = editOptions.value.splice(index, 1);
  editOptions.value.splice(target, 0, item);
}

function saveOptions(): void {
  if (!selected.value || pending.value || optionsUnchanged.value) return;
  emit('updateOptions', selected.value.id, [...editOptions.value]);
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
const typeOptions = Object.entries(TYPE_LABELS).map(([value, text]) => ({ value, text, icon: ATTRIBUTE_TYPE_ICONS[value] }));
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
      <div class="col-md-4 wtf-master-column">
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
              <SearchableSelect v-model="newType" :options="typeOptions" aria-label="Type" />
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

          <p v-if="filteredDefinitions.length === 0" class="text-muted small wtf-master-list">No attributes found.</p>
          <div v-else class="list-group wtf-master-list">
            <button
              v-for="definition in filteredDefinitions"
              :key="definition.id"
              type="button"
              class="list-group-item list-group-item-action"
              :class="{ active: selectedId === definition.id }"
              @click="select(definition)"
            >
              <AppIcon v-if="ATTRIBUTE_TYPE_ICONS[definition.type]" :icon="ATTRIBUTE_TYPE_ICONS[definition.type]!" class="me-2" />
              <EncryptedText :value="definition.label" :ciphertext="definition.label_ciphertext" />
              <!-- Delete-protected: a site feature depends on it. The same blue as the "Used by" pill and the notice (info), except on the selected row, which is blue itself, where white stays visible. -->
              <span
                v-if="featuresOf(definition.id).length"
                class="ms-1 field-protected"
                :class="selectedId === definition.id ? '' : 'text-info'"
                :title="`Used by ${featuresOf(definition.id).join(', ')} — can't be deleted`"
              >
                <AppIcon :icon="LockIcon" />
                <span class="visually-hidden">Used by {{ featuresOf(definition.id).join(', ') }}; can't be deleted</span>
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
          <BFormGroup
            v-if="selected.type === 'radio'"
            label="Choices"
            description="You can add choices and reorder them. Saved choices can't be renamed or removed, since connections may already use them."
            class="mb-3"
          >
            <ul class="list-unstyled mb-2">
              <li v-for="(option, index) in editOptions" :key="option" class="d-flex align-items-center gap-1 mb-1">
                <span class="flex-grow-1">{{ option }}</span>
                <BButton size="sm" variant="outline-secondary" :disabled="index === 0" :aria-label="`Move ${option} up`" @click="moveOption(index, -1)">↑</BButton>
                <BButton size="sm" variant="outline-secondary" :disabled="index === editOptions.length - 1" :aria-label="`Move ${option} down`" @click="moveOption(index, 1)">↓</BButton>
                <BButton
                  v-if="!selected.options.includes(option)"
                  size="sm"
                  variant="outline-danger"
                  :aria-label="`Discard new choice ${option}`"
                  @click="removeUnsavedOption(index)"
                >
                  ×
                </BButton>
              </li>
            </ul>
            <div class="d-flex gap-1">
              <BFormInput v-model="newOption" type="text" size="sm" placeholder="New choice" @keyup.enter="addOption" />
              <BButton size="sm" variant="outline-secondary" :disabled="!newOption.trim() || editOptions.includes(newOption.trim())" @click="addOption">Add</BButton>
              <BButton size="sm" variant="primary" :disabled="pending || optionsUnchanged" @click="saveOptions">Save choices</BButton>
            </div>
          </BFormGroup>
          <dl class="row small mb-3">
            <dt class="col-sm-3">Type</dt>
            <dd class="col-sm-9">
              <AppIcon v-if="ATTRIBUTE_TYPE_ICONS[selected.type]" :icon="ATTRIBUTE_TYPE_ICONS[selected.type]!" class="me-1" />
              {{ TYPE_LABELS[selected.type] ?? selected.type }}
              <span v-if="!pathsFrom(migrations, selected.type).length" class="text-muted">(fixed: nothing to migrate it to)</span>
              <span v-else class="text-muted">(see Migrate field below)</span>
            </dd>
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
          <MigrateFieldSection
            :definition="selected"
            :paths="migrations"
            :type-labels="TYPE_LABELS"
            :usage="usage[selected.id] ?? 0"
            :used-by="featuresOf(selected.id)"
            :feature-types="fieldFeatureTypes"
            :identifier-targets="identifierDefinitions.map((d) => ({ id: d.id, label: d.label }))"
            :copy-status="copyStatus"
            :busy="copyBusy"
            :disabled="pending"
            @migrate="(id, to, options) => emit('migrate', id, to, options)"
            @copy-to-identifier="(from, to) => emit('copyToIdentifier', from, to)"
            @create-identifier-and-copy="(from, label) => emit('createIdentifierAndCopy', from, label)"
          />
          <IconRulesEditor
            :type="selected.type"
            :choices="selected.options"
            :parsed="iconRuleSets[selected.id]"
            :pending="pending"
            @save="emit('saveIconRules', selected.id, $event)"
          />
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

  </div>
</template>
