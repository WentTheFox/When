<script setup lang="ts">
/**
 * Shared "how many of these" control for every block type that can carry a
 * QuantifierMod (regexAstModel.ts) — anchors are the one node type that
 * never gets one (see that file's own note on why EdgeAssertion/
 * WordBoundaryAssertion can never be QuantifiableElements in the parsed
 * grammar), so RegexBlockNode.vue simply never mounts this for those.
 */
import { computed } from 'vue';
import type { QuantifierMod } from './regexAstModel';

const quantifier = defineModel<QuantifierMod | undefined>();

type SelectKind = 'none' | '*' | '+' | '?' | 'range';

const selectKind = computed<SelectKind>({
  get: () => quantifier.value?.kind ?? 'none',
  set: (kind) => {
    if (kind === 'none') {
      quantifier.value = undefined;
      return;
    }
    if (kind === 'range') {
      quantifier.value = { kind: 'range', min: 0, max: null, greedy: quantifier.value?.greedy ?? true };
      return;
    }
    quantifier.value = { kind, greedy: quantifier.value?.greedy ?? true };
  },
});

function setMin(value: string): void {
  if (!quantifier.value || quantifier.value.kind !== 'range') return;
  quantifier.value = { ...quantifier.value, min: Math.max(0, Number(value) || 0) };
}

function setMax(value: string): void {
  if (!quantifier.value || quantifier.value.kind !== 'range') return;
  quantifier.value = { ...quantifier.value, max: value === '' ? null : Math.max(0, Number(value) || 0) };
}

function toggleGreedy(): void {
  if (!quantifier.value) return;
  quantifier.value = { ...quantifier.value, greedy: !quantifier.value.greedy };
}
</script>

<template>
  <span class="wtf-regex-quantifier">
    <select v-model="selectKind" class="form-select form-select-sm wtf-regex-quantifier-select" aria-label="Repeat count">
      <option value="none">once</option>
      <option value="*">any number (*)</option>
      <option value="+">one or more (+)</option>
      <option value="?">optional (?)</option>
      <option value="range">between…</option>
    </select>
    <template v-if="quantifier?.kind === 'range'">
      <input
        type="number"
        min="0"
        class="form-control form-control-sm wtf-regex-quantifier-number"
        :value="quantifier.min ?? 0"
        aria-label="Minimum repeats"
        @change="setMin(($event.target as HTMLInputElement).value)"
      >
      <span class="wtf-regex-quantifier-dash">–</span>
      <input
        type="number"
        min="0"
        class="form-control form-control-sm wtf-regex-quantifier-number"
        :value="quantifier.max ?? ''"
        placeholder="∞"
        aria-label="Maximum repeats"
        @change="setMax(($event.target as HTMLInputElement).value)"
      >
    </template>
    <label v-if="quantifier" class="wtf-regex-quantifier-greedy">
      <input type="checkbox" :checked="quantifier.greedy" @change="toggleGreedy">
      greedy
    </label>
  </span>
</template>
