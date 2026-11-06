<script setup lang="ts">
/**
 * Per-field icon rules: "when this field's value is X, show this icon next to the connection".
 * The whole rule list is saved as one end-to-end-encrypted blob (see iconRules.ts and useRuleIcons.ts);
 * this component only edits a draft and hands the finished rule set to its parent to encrypt and save.
 */
import { BAlert, BButton, BFormCheckbox, BFormInput, BFormSelect } from 'bootstrap-vue-next';
import { computed, ref, watch } from 'vue';
import ColorPicker from './ColorPicker.vue';
import IconPicker from './IconPicker.vue';
import PatternPreview from './PatternPreview.vue';
import RegexPatternInput from './RegexPatternInput.vue';
import {
  allowedOps,
  compileRulePattern,
  emptyRuleSet,
  MAX_RULES_PER_FIELD,
} from './iconRules';
import type { IconRule, IconRuleSet, ParsedRuleSet, RuleCondition, RuleOp } from './iconRules';
import type { PatternPreviewConfig } from './patternPreviewTypes';

const props = defineProps<{
  /** The field's type, which decides the conditions on offer. */
  type: string;
  /** A choice field's options, for the "is one of" condition. */
  choices: string[];
  /** The saved rules once decrypted; undefined while the field has none. */
  parsed?: ParsedRuleSet;
  /** True until the field's label has decrypted, i.e. the vault is still locked. */
  pending: boolean;
}>();

const emit = defineEmits<{ save: [IconRuleSet] }>();

const OP_LABELS: Record<RuleOp, string> = {
  nonEmpty: 'has a value',
  empty: 'is empty',
  isTrue: 'is Yes',
  isFalse: 'is No',
  choiceIn: 'is one of',
  regex: 'matches pattern',
  range: 'is within range',
};

/** The value preview just answers "does this match?" for each example line, the same way the activity and DND pattern fields do. */
const PREVIEW_CONFIG: PatternPreviewConfig = {
  mode: 'match',
  examples: ['https://example.com/profile', 'plain text value', 'Another Value'],
  placeholder: 'Type example values to test the pattern, one per line',
};

const ops = computed(() => allowedOps(props.type).map((op) => ({ value: op, text: OP_LABELS[op] })));

function clone(set: IconRuleSet): IconRuleSet {
  return JSON.parse(JSON.stringify(set)) as IconRuleSet;
}

const baseline = computed<IconRuleSet>(() => (props.parsed?.kind === 'ok' ? props.parsed.set : emptyRuleSet()));
const draft = ref<IconRuleSet>(clone(baseline.value));
/** Set when the saved blob couldn't be read: the owner may start over, which replaces it. */
const startedOver = ref(false);

watch([baseline, () => props.type], () => {
  draft.value = clone(baseline.value);
  startedOver.value = false;
}, { deep: true });

const newerVersion = computed(() => (props.parsed?.kind === 'newer' ? props.parsed.version : null));
const unreadable = computed(() => props.parsed?.kind === 'invalid' && !startedOver.value);

function conditionFor(op: RuleOp): RuleCondition {
  switch (op) {
    case 'choiceIn':
      return { op, choices: [] };
    case 'regex':
      return { op, pattern: '' };
    default:
      return { op };
  }
}

function addRule(): void {
  if (draft.value.rules.length >= MAX_RULES_PER_FIELD) return;
  draft.value.rules.push({ id: crypto.randomUUID(), icon: 'star', color: null, condition: conditionFor(allowedOps(props.type)[0]) });
}

function removeRule(index: number): void {
  draft.value.rules.splice(index, 1);
}

function moveRule(index: number, delta: number): void {
  const target = index + delta;
  if (target < 0 || target >= draft.value.rules.length) return;
  const [rule] = draft.value.rules.splice(index, 1);
  draft.value.rules.splice(target, 0, rule);
}

function setOp(rule: IconRule, op: RuleOp): void {
  rule.condition = conditionFor(op);
}

function toggleChoice(rule: IconRule, choice: string, checked: boolean): void {
  if (rule.condition.op !== 'choiceIn') return;
  const set = new Set(rule.condition.choices);
  if (checked) set.add(choice);
  else set.delete(choice);
  // Keep the options' own order, whatever order they were ticked in.
  rule.condition.choices = props.choices.filter((c) => set.has(c));
}

function setBound(rule: IconRule, bound: 'min' | 'max', raw: string): void {
  if (rule.condition.op !== 'range') return;
  const number = raw.trim() === '' ? undefined : Number(raw);
  if (number === undefined || !Number.isFinite(number)) delete rule.condition[bound];
  else rule.condition[bound] = number;
}

function patternInvalid(rule: IconRule): boolean {
  return rule.condition.op === 'regex' && rule.condition.pattern !== '' && compileRulePattern(rule.condition.pattern) === null;
}

/** Why a rule can't be saved yet, or null when it is complete. */
function problemWith(rule: IconRule): string | null {
  const condition = rule.condition;
  if (!rule.icon) return 'Choose an icon.';
  if (condition.op === 'choiceIn' && condition.choices.length === 0) return 'Tick at least one choice.';
  if (condition.op === 'regex') {
    if (condition.pattern === '') return 'Enter a pattern.';
    if (patternInvalid(rule)) return 'This pattern is not valid (or is over 200 characters).';
  }
  if (condition.op === 'range') {
    if (condition.min === undefined && condition.max === undefined) return 'Set a minimum, a maximum, or both.';
    if (condition.min !== undefined && condition.max !== undefined && condition.min > condition.max) return 'The minimum is above the maximum.';
  }

  return null;
}

const dirty = computed(() => JSON.stringify(draft.value) !== JSON.stringify(baseline.value) || startedOver.value);
const complete = computed(() => draft.value.rules.every((rule) => problemWith(rule) === null));
const readOnly = computed(() => props.pending || newerVersion.value !== null || unreadable.value);

function save(): void {
  if (readOnly.value || !dirty.value || !complete.value) return;

  const out = clone(draft.value);
  for (const rule of out.rules) rule.label = rule.label?.trim() || undefined;
  emit('save', out);
}

function discard(): void {
  draft.value = clone(baseline.value);
  startedOver.value = false;
}
</script>

<template>
  <div class="mb-3">
    <p class="fw-semibold mb-1">Icon rules</p>
    <p class="small text-muted mb-2">
      Show an icon next to a connection in the list depending on this field's value. Rules are checked in the order shown, and every rule that matches adds its icon.
      They are end-to-end encrypted, like the field itself.
    </p>

    <BAlert :model-value="newerVersion !== null" variant="warning" class="small py-2">
      These rules were saved by a newer version of the app than this page. Reload the page to edit them; they are left untouched until then.
    </BAlert>
    <BAlert :model-value="unreadable" variant="warning" class="small py-2">
      The saved rules could not be read.
      <BButton size="sm" variant="outline-danger" class="ms-2" @click="startedOver = true; draft = emptyRuleSet()">Start over</BButton>
    </BAlert>

    <template v-if="newerVersion === null && !unreadable">
      <p v-if="draft.rules.length === 0" class="small text-muted fst-italic mb-2">No rules yet.</p>

      <div v-for="(rule, index) in draft.rules" :key="rule.id" class="border rounded p-2 mb-2">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
          <span class="small text-muted">When the value</span>
          <BFormSelect
            :model-value="rule.condition.op"
            :options="ops"
            size="sm"
            class="w-auto"
            :aria-label="`Condition for rule ${index + 1}`"
            @update:model-value="setOp(rule, $event as RuleOp)"
          />

          <template v-if="rule.condition.op === 'range'">
            <input
              type="number"
              class="form-control form-control-sm w-auto"
              style="max-width: 7rem"
              placeholder="min"
              :value="rule.condition.min ?? ''"
              aria-label="Minimum"
              @input="setBound(rule, 'min', ($event.target as HTMLInputElement).value)"
            />
            <span class="small text-muted">to</span>
            <input
              type="number"
              class="form-control form-control-sm w-auto"
              style="max-width: 7rem"
              placeholder="max"
              :value="rule.condition.max ?? ''"
              aria-label="Maximum"
              @input="setBound(rule, 'max', ($event.target as HTMLInputElement).value)"
            />
          </template>

        </div>

        <div v-if="rule.condition.op === 'regex'" class="mb-2">
          <RegexPatternInput
            :id="`icon-rule-pattern-${rule.id}`"
            v-model="rule.condition.pattern"
            v-model:preview-model-value="rule.condition.preview"
            :field-label="`Icon rule ${index + 1} pattern`"
            :preview-config="PREVIEW_CONFIG"
            placeholder="e.g. ^https://"
          />
          <p class="small text-muted mt-1 mb-1">
            Matched without regard to case. Try it on example values below; one per line, saved with the rule (encrypted).
          </p>
          <PatternPreview
            v-model="rule.condition.preview"
            :pattern="rule.condition.pattern"
            v-bind="PREVIEW_CONFIG"
          />
        </div>

        <div v-if="rule.condition.op === 'choiceIn'" class="mb-2">
          <p v-if="choices.length === 0" class="small text-muted fst-italic mb-0">This field has no choices yet.</p>
          <label v-for="choice in choices" :key="choice" class="d-inline-flex align-items-center gap-1 me-3 small">
            <input
              type="checkbox"
              class="form-check-input mt-0"
              :checked="rule.condition.choices.includes(choice)"
              @change="toggleChoice(rule, choice, ($event.target as HTMLInputElement).checked)"
            />
            {{ choice }}
          </label>
        </div>

        <div class="d-flex flex-wrap align-items-center gap-2">
          <span class="small text-muted">show</span>
          <IconPicker
            :model-value="rule.icon"
            :label="`Icon for rule ${index + 1}`"
            :active-color="undefined"
            @update:model-value="rule.icon = $event ?? rule.icon"
          />
          <ColorPicker v-model="rule.color" :label="`Colour for rule ${index + 1}`" preview="solid" />
          <BFormInput
            :model-value="rule.label ?? ''"
            size="sm"
            class="w-auto flex-grow-1"
            maxlength="60"
            placeholder="Tooltip (optional)"
            :aria-label="`Tooltip for rule ${index + 1}`"
            @update:model-value="rule.label = String($event)"
          />
          <span class="ms-auto d-inline-flex gap-1">
            <BButton size="sm" variant="outline-secondary" :disabled="index === 0" :aria-label="`Move rule ${index + 1} up`" @click="moveRule(index, -1)">↑</BButton>
            <BButton size="sm" variant="outline-secondary" :disabled="index === draft.rules.length - 1" :aria-label="`Move rule ${index + 1} down`" @click="moveRule(index, 1)">↓</BButton>
            <BButton size="sm" variant="outline-danger" :aria-label="`Remove rule ${index + 1}`" @click="removeRule(index)">×</BButton>
          </span>
        </div>

        <p v-if="problemWith(rule)" class="small text-danger mb-0 mt-1">{{ problemWith(rule) }}</p>
      </div>

      <BFormCheckbox v-model="draft.stopAfterFirst" class="small mb-2">
        Only show the first matching rule for this field
      </BFormCheckbox>

      <div class="d-flex gap-2">
        <BButton size="sm" variant="outline-primary" :disabled="readOnly || draft.rules.length >= MAX_RULES_PER_FIELD" @click="addRule">Add rule</BButton>
        <BButton size="sm" variant="primary" :disabled="readOnly || !dirty || !complete" @click="save">Save rules</BButton>
        <BButton v-if="dirty" size="sm" variant="outline-secondary" @click="discard">Discard changes</BButton>
      </div>
    </template>
  </div>
</template>
