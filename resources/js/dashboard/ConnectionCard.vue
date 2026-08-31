<script setup lang="ts">
import axios from 'axios';
import {
  BBadge,
  BButton,
  BCard,
  BFormCheckbox,
  BFormGroup,
  BFormInput,
  BFormRadio,
  BFormSelect,
  BFormTextarea,
  type InputType,
} from 'bootstrap-vue-next';
import { computed, onMounted, ref } from 'vue';
import { decryptString, encryptString } from '../crypto';
import { useVault } from './useVault';

interface AttributeValue {
  attribute_definition_id: string;
  value_ciphertext: string;
}

export interface ConnectionRow {
  id: string;
  source_ids: string[];
  name_ciphertext: string;
  notes_ciphertext: string | null;
  archived: boolean;
  attribute_values: AttributeValue[];
}

const props = defineProps<{
  connection: ConnectionRow;
  sources: { id: string; label: string }[];
  attributeDefinitions: { id: string; label: string; type: string; options: string[] }[];
}>();
const emit = defineEmits<{ updated: [ConnectionRow]; deleted: [string] }>();

const { getRecordKey } = useVault();

const name = ref('');
const notes = ref('');
const attributeValues = ref<Record<string, string>>({});
const editing = ref(false);

const editName = ref('');
const editNotes = ref('');
const editSourceIds = ref<string[]>([...props.connection.source_ids]);
const editArchived = ref(props.connection.archived);
const editAttributeValues = ref<Record<string, string>>({});

const sortedSources = computed(() => [...props.sources].sort((a, b) => a.label.localeCompare(b.label)));

async function decryptAll(): Promise<void> {
  try {
    const key = await getRecordKey(props.connection.id);
    name.value = await decryptString(key, props.connection.name_ciphertext);
    notes.value = props.connection.notes_ciphertext
      ? await decryptString(key, props.connection.notes_ciphertext)
      : '';

    const values: Record<string, string> = {};
    for (const attributeValue of props.connection.attribute_values) {
      values[attributeValue.attribute_definition_id] = await decryptString(key, attributeValue.value_ciphertext);
    }
    attributeValues.value = values;
  } catch (error) {
    console.error(error);
    name.value = '(could not decrypt)';
  }
}

onMounted(decryptAll);

function sourceLabels(ids: string[]): string {
  return ids.map((id) => props.sources.find((s) => s.id === id)?.label ?? '').filter(Boolean).join(', ');
}

function attributeLabel(id: string): string {
  return props.attributeDefinitions.find((d) => d.id === id)?.label ?? id;
}

const INPUT_TYPE: Record<string, InputType> = { phone: 'tel' };
function inputType(type: string): InputType {
  return INPUT_TYPE[type] ?? (type as InputType);
}

function startEdit(): void {
  editName.value = name.value;
  editNotes.value = notes.value;
  editSourceIds.value = [...props.connection.source_ids];
  editArchived.value = props.connection.archived;
  editAttributeValues.value = { ...attributeValues.value };
  editing.value = true;
}

async function save(): Promise<void> {
  try {
    const key = await getRecordKey(props.connection.id);

    const values = [];
    for (const definition of props.attributeDefinitions) {
      const value = editAttributeValues.value[definition.id];
      if (value) {
        values.push({
          attribute_definition_id: definition.id,
          value_ciphertext: await encryptString(key, value),
        });
      }
    }

    const { data } = await axios.patch(`/dashboard/connections/${props.connection.id}`, {
      source_ids: editSourceIds.value,
      name_ciphertext: await encryptString(key, editName.value),
      notes_ciphertext: editNotes.value ? await encryptString(key, editNotes.value) : null,
      archived: editArchived.value,
      attribute_values: values,
    });

    name.value = editName.value;
    notes.value = editNotes.value;
    attributeValues.value = { ...editAttributeValues.value };
    editing.value = false;
    emit('updated', data);
  } catch (error) {
    console.error(error);
  }
}

async function remove(): Promise<void> {
  if (!window.confirm('Delete this connection?')) return;
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
    <div class="d-flex justify-content-between align-items-start">
      <div>
        <h3 class="h6 mb-1">
          {{ name }}
          <BBadge v-if="connection.archived" variant="secondary" class="ms-1">Archived</BBadge>
        </h3>
        <p v-if="connection.source_ids.length" class="small text-muted mb-1">{{ sourceLabels(connection.source_ids) }}</p>
        <p v-if="notes" class="small mb-0">{{ notes }}</p>
        <dl class="row small mb-0 mt-2">
          <template v-for="(value, definitionId) in attributeValues" :key="definitionId">
            <div v-if="value" class="col-12">{{ attributeLabel(definitionId) }}: {{ value }}</div>
          </template>
        </dl>
      </div>
      <div>
        <BButton variant="outline-secondary" size="sm" @click="startEdit">Edit</BButton>
        <BButton variant="outline-danger" size="sm" @click="remove">Delete</BButton>
      </div>
    </div>

    <div v-if="editing" class="mt-3">
      <div class="row">
        <div class="col-md-6">
          <BFormGroup label="Name" class="mb-3">
            <BFormInput v-model="editName" type="text" size="sm" />
          </BFormGroup>
        </div>
        <div class="col-md-6">
          <BFormGroup label="Sources" class="mb-3">
            <BFormSelect v-model="editSourceIds" multiple size="sm">
              <option v-for="source in sortedSources" :key="source.id" :value="source.id">{{ source.label }}</option>
            </BFormSelect>
          </BFormGroup>
        </div>
      </div>
      <BFormGroup label="Notes" class="mb-3">
        <BFormTextarea v-model="editNotes" size="sm" rows="2" />
      </BFormGroup>
      <BFormCheckbox :id="`archived-${connection.id}`" v-model="editArchived" class="mb-3">Archived</BFormCheckbox>
      <BFormGroup v-for="definition in attributeDefinitions" :key="definition.id" :label="definition.label" class="mb-3">
        <BFormTextarea
          v-if="definition.type === 'textarea'"
          v-model="editAttributeValues[definition.id]"
          size="sm"
          rows="2"
        />
        <div v-else-if="definition.type === 'radio'">
          <BFormRadio
            v-for="choice in definition.options"
            :key="choice"
            v-model="editAttributeValues[definition.id]"
            :value="choice"
          >
            {{ choice }}
          </BFormRadio>
        </div>
        <BFormInput
          v-else
          v-model="editAttributeValues[definition.id]"
          :type="inputType(definition.type)"
          size="sm"
        />
      </BFormGroup>
      <BButton size="sm" variant="primary" @click="save">Save</BButton>
    </div>
  </BCard>
</template>
