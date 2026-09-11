<script setup lang="ts">
/**
 * CRUD list for App\Models\ActivityLocalization — generalizes the old hardcoded
 * "Host X"/"Visit X" convention into an owner-configurable, ordered list
 * of (pattern, localized label, icon) triples. Each role's own pattern is
 * matched the same way highlight_clause_pattern is (see
 * HighlightMatcher) — requires exactly one real capture group, the name
 * portion. Not §0.1 client-vault E2EE — pattern/pattern_preview are §0.2
 * server-runtime Crypt/APP_KEY ciphertext instead (see
 * ActivityLocalization::casts()), transparently handled server-side; this
 * component still only ever sends/receives their plaintext form. label
 * stays genuinely plaintext (a separate localized_texts row) — and is
 * entirely optional, unlike pattern: an owner who only wants a matched
 * event's icon to change, not its wording, can leave every label field
 * blank.
 *
 * Rendered as an accordion (one collapsible item per role) rather than a
 * flat stack of always-expanded panels — each item's own header shows a
 * live summary (pattern, configured label language codes, icon) of that
 * role's current, possibly-unsaved edits, since the header is a slot
 * bound to the same reactive `role` object the form inside edits
 * directly, not a snapshot taken at render time.
 */
import axios from 'axios';
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome';
import { faLanguage } from '@fortawesome/free-solid-svg-icons';
import { BAccordion, BAccordionItem, BButton } from 'bootstrap-vue-next';
import { ref } from 'vue';
import { faIconFor } from '../free/icon-palette';
import ActivityLocalizationForm from './ActivityLocalizationForm.vue';

interface ActivityLocalizationData {
  id: string;
  pattern: string;
  pattern_preview: string | null;
  label: Record<string, string>;
  sort_order: number;
  icon_key: string | null;
  color_key: string | null;
}

const props = defineProps<{ initial: ActivityLocalizationData[] }>();

const roles = ref<ActivityLocalizationData[]>([...props.initial].sort((a, b) => a.sort_order - b.sort_order));
const savingId = ref<string | null>(null);
const savedId = ref<string | null>(null);
const errors = ref<Record<string, string>>({});

const newPattern = ref('');
const newPatternPreview = ref<string | null>(null);
const newLabel = ref<Record<string, string>>({});
const newIconKey = ref<string | null>(null);
const newColorKey = ref<string | null>(null);
const adding = ref(false);
const addError = ref('');

/** Every language this role's label has been given text for (including "default" itself) — shown in the accordion header as a quick summary of what's actually configured. */
function labelCodes(label: Record<string, string>): string[] {
  return Object.keys(label);
}

async function save(role: ActivityLocalizationData): Promise<void> {
  savingId.value = role.id;
  savedId.value = null;
  errors.value[role.id] = '';

  try {
    await axios.patch(`/settings/activity-localization/${role.id}`, {
      pattern: role.pattern,
      pattern_preview: role.pattern_preview,
      label: role.label,
      sort_order: role.sort_order,
      icon_key: role.icon_key,
      color_key: role.color_key,
    });
    savedId.value = role.id;
  } catch (e) {
    console.error(e);
    errors.value[role.id] = 'Could not save that customization — check the pattern has exactly one capture group.';
  } finally {
    savingId.value = null;
  }
}

const addPanel = ref<HTMLElement | null>(null);

/**
 * Copies an existing role's pattern/preview/label/icon/color into the "Add
 * a customization" form below, for the common case of wanting a near-
 * duplicate of an existing rule (e.g. the same pattern shape for a
 * different name, or the same icon/label under a different pattern)
 * instead of re-entering every field from scratch. Doesn't save anything
 * itself — the owner still edits the copy and clicks "Add customization"
 * like any other new role. `label` is spread into a new object so editing
 * the copy can never mutate the role it was copied from.
 */
function cloneToNew(role: ActivityLocalizationData): void {
  newPattern.value = role.pattern;
  newPatternPreview.value = role.pattern_preview;
  newLabel.value = { ...role.label };
  newIconKey.value = role.icon_key;
  newColorKey.value = role.color_key;
  addError.value = '';

  addPanel.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

async function remove(role: ActivityLocalizationData): Promise<void> {
  try {
    await axios.delete(`/settings/activity-localization/${role.id}`);
    roles.value = roles.value.filter((r) => r.id !== role.id);
  } catch (e) {
    console.error(e);
  }
}

async function add(): Promise<void> {
  addError.value = '';

  // Label is optional (an icon-only customization is a real use case —
  // see this file's own header comment); only the pattern is genuinely
  // required, since there's nothing to match without one.
  if (!newPattern.value) {
    addError.value = 'A pattern is required.';
    return;
  }

  adding.value = true;

  try {
    const id = crypto.randomUUID();
    const sortOrder = roles.value.length;

    await axios.post('/settings/activity-localization', {
      id,
      pattern: newPattern.value,
      pattern_preview: newPatternPreview.value,
      label: newLabel.value,
      sort_order: sortOrder,
      icon_key: newIconKey.value,
      color_key: newColorKey.value,
    });

    roles.value.push({
      id, pattern: newPattern.value, pattern_preview: newPatternPreview.value, label: newLabel.value, sort_order: sortOrder, icon_key: newIconKey.value, color_key: newColorKey.value,
    });
    newPattern.value = '';
    newPatternPreview.value = null;
    newLabel.value = {};
    newIconKey.value = null;
    newColorKey.value = null;
  } catch (e) {
    console.error(e);
    addError.value = 'Could not add that customization — check the pattern has exactly one capture group.';
  } finally {
    adding.value = false;
  }
}
</script>

<template>
  <h2 class="h5 mb-3">Activity customizations</h2>
  <p class="small text-muted">
    Each pattern has the same rules as the fields above: exactly one <code>(…)</code> capture
    group to define the matched name(s). Maps to a label shown to the viewer instead of raw extracted
    activity text — entirely optional, so a customization can just change the icon instead. Besides
    the possibility to translate activities, another possible use-case could be hosting/visiting —
    the label can be changed to <em>the viewer's perspective</em>. If an event's title is "Host
    Alice" that means Alice is visiting the calendar owner, so its label can be changed to "Visiting"
    when she's reading the calendar.
  </p>

  <BAccordion free class="mb-3">
    <BAccordionItem v-for="role in roles" :key="role.id">
      <template #title>
        <span class="d-flex align-items-center gap-2 flex-wrap">
          <FontAwesomeIcon v-if="role.icon_key && faIconFor(role.icon_key)" :icon="faIconFor(role.icon_key)!" />
          <code>{{ role.pattern || '(no pattern yet)' }}</code>
          <span v-if="labelCodes(role.label).length" class="small text-muted"><FontAwesomeIcon :icon="faLanguage" class="me-1"/>{{ labelCodes(role.label).join(', ') }}</span>
        </span>
      </template>
      <ActivityLocalizationForm
        v-model:pattern="role.pattern"
        v-model:preview-text="role.pattern_preview"
        v-model:label="role.label"
        v-model:icon-key="role.icon_key"
        v-model:color-key="role.color_key"
        :id-prefix="`activity_localization_${role.id}`"
      />
      <BButton variant="primary" size="sm" :disabled="savingId === role.id" @click="save(role)">Save</BButton>
      <BButton variant="outline-secondary" size="sm" class="ms-2" @click="cloneToNew(role)">Copy to new</BButton>
      <BButton variant="outline-danger" size="sm" class="ms-2" @click="remove(role)">Remove</BButton>
      <span v-if="savedId === role.id" class="small text-success ms-2">Saved</span>
      <div v-if="errors[role.id]" class="text-danger small mt-1">{{ errors[role.id] }}</div>
    </BAccordionItem>
  </BAccordion>

  <div ref="addPanel" class="wtf-pattern-preview-panel">
    <p class="small fw-semibold mb-2">Add a customization</p>
    <ActivityLocalizationForm
      v-model:pattern="newPattern"
      v-model:preview-text="newPatternPreview"
      v-model:label="newLabel"
      v-model:icon-key="newIconKey"
      v-model:color-key="newColorKey"
      id-prefix="new_activity_localization"
    />
    <BButton variant="primary" :disabled="adding" @click="add">Add customization</BButton>
    <div v-if="addError" class="text-danger small mt-1">{{ addError }}</div>
  </div>
</template>
