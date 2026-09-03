<script setup lang="ts">
/**
 * CRUD list for App\Models\ActivityRole — generalizes the old hardcoded
 * "Host X"/"Visit X" convention into an owner-configurable, ordered list
 * of (pattern, localized label) pairs. Each role's own pattern is
 * matched the same way highlight_clause_pattern is (see
 * HighlightMatcher) — requires exactly one real capture group, the name
 * portion. Not §0.1 E2EE (unlike SleepExceptions' own optional note):
 * both pattern and label are owner-authored text, not extracted from
 * calendar content, so there's nothing here the server needs to avoid
 * seeing.
 */
import axios from 'axios';
import { BButton, BFormGroup } from 'bootstrap-vue-next';
import { ref } from 'vue';
import LocalizedTextInput from './LocalizedTextInput.vue';
import RegexPatternInput from './RegexPatternInput.vue';

interface ActivityRoleData {
  id: string;
  pattern: string;
  label: Record<string, string>;
  sort_order: number;
}

const props = defineProps<{ initial: ActivityRoleData[] }>();

const roles = ref<ActivityRoleData[]>([...props.initial].sort((a, b) => a.sort_order - b.sort_order));
const savingId = ref<string | null>(null);
const savedId = ref<string | null>(null);
const errors = ref<Record<string, string>>({});

const newPattern = ref('');
const newLabel = ref<Record<string, string>>({});
const adding = ref(false);
const addError = ref('');

async function save(role: ActivityRoleData): Promise<void> {
  savingId.value = role.id;
  savedId.value = null;
  errors.value[role.id] = '';

  try {
    await axios.patch(`/settings/activity-roles/${role.id}`, {
      pattern: role.pattern,
      label: role.label,
      sort_order: role.sort_order,
    });
    savedId.value = role.id;
  } catch (e) {
    console.error(e);
    errors.value[role.id] = 'Could not save that role — check the pattern has exactly one capture group.';
  } finally {
    savingId.value = null;
  }
}

async function remove(role: ActivityRoleData): Promise<void> {
  try {
    await axios.delete(`/settings/activity-roles/${role.id}`);
    roles.value = roles.value.filter((r) => r.id !== role.id);
  } catch (e) {
    console.error(e);
  }
}

async function add(): Promise<void> {
  addError.value = '';

  if (!newPattern.value || !newLabel.value.default) {
    addError.value = 'A pattern and a default label are both required.';
    return;
  }

  adding.value = true;

  try {
    const id = crypto.randomUUID();
    const sortOrder = roles.value.length;

    await axios.post('/settings/activity-roles', {
      id,
      pattern: newPattern.value,
      label: newLabel.value,
      sort_order: sortOrder,
    });

    roles.value.push({ id, pattern: newPattern.value, label: newLabel.value, sort_order: sortOrder });
    newPattern.value = '';
    newLabel.value = {};
  } catch (e) {
    console.error(e);
    addError.value = 'Could not add that role — check the pattern has exactly one capture group.';
  } finally {
    adding.value = false;
  }
}
</script>

<template>
  <h3 class="h6 mb-2">Activity roles</h3>
  <p class="small text-muted">
    Each role is a pattern (same rules as the fields above — exactly one <code>(…)</code> capture
    group, the matched name) plus a label shown to that person instead of raw extracted activity
    text. The two below are the classic "Host X"/"Visit X" convention, now yours to edit or
    remove — the label is <em>the viewer's own role</em>, not the owner's: an owner's "Host
    Alice" title means Alice herself is visiting, so its label reads "Visiting", not "Hosting".
  </p>

  <div v-for="role in roles" :key="role.id" class="wtf-pattern-preview-panel mb-3">
    <div class="row">
      <div class="col-md-6">
        <BFormGroup label="Pattern" :label-for="`activity_role_pattern_${role.id}`" class="mb-2">
          <RegexPatternInput :id="`activity_role_pattern_${role.id}`" v-model="role.pattern" />
        </BFormGroup>
      </div>
      <div class="col-md-6">
        <LocalizedTextInput
          v-model="role.label"
          :id="`activity_role_label_${role.id}`"
          label="Label shown to the viewer"
          default-placeholder="Visiting"
        />
      </div>
    </div>
    <BButton variant="primary" size="sm" :disabled="savingId === role.id" @click="save(role)">Save</BButton>
    <BButton variant="outline-danger" size="sm" class="ms-2" @click="remove(role)">Remove</BButton>
    <span v-if="savedId === role.id" class="small text-success ms-2">Saved</span>
    <div v-if="errors[role.id]" class="text-danger small mt-1">{{ errors[role.id] }}</div>
  </div>

  <div class="wtf-pattern-preview-panel">
    <p class="small fw-semibold mb-2">Add a role</p>
    <div class="row">
      <div class="col-md-6">
        <BFormGroup label="Pattern" label-for="new_activity_role_pattern" class="mb-2">
          <RegexPatternInput id="new_activity_role_pattern" v-model="newPattern" />
        </BFormGroup>
      </div>
      <div class="col-md-6">
        <LocalizedTextInput
          v-model="newLabel"
          id="new_activity_role_label"
          label="Label shown to the viewer"
          default-placeholder="Visiting"
          required
        />
      </div>
    </div>
    <BButton variant="outline-secondary" :disabled="adding" @click="add">Add role</BButton>
    <div v-if="addError" class="text-danger small mt-1">{{ addError }}</div>
  </div>
</template>
