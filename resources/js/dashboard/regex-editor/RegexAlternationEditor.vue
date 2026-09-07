<script setup lang="ts">
/**
 * Renders an AlternationNode's branches (each a SequenceNode) with "OR"
 * dividers between them — used both for the whole pattern (top level, in
 * RegexVisualEditorModal.vue) and recursively for every group's own body
 * (RegexBlockNode.vue), since both are the exact same AlternationNode
 * shape (regexAstModel.ts's own note on why: it mirrors regexpp's
 * Pattern.alternatives / Group.alternatives 1:1).
 */
import { faPlus, faTrash } from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome';
import { BButton } from 'bootstrap-vue-next';
import { emptySequence, type AlternationNode } from './regexAstModel';
import RegexSequenceEditor from './RegexSequenceEditor.vue';

const props = withDefaults(defineProps<{
  alternation: AlternationNode;
  /**
   * Whether ^/$ can be dropped into any of this alternation's own branches
   * — true at the root (RegexVisualEditorModal.vue's own call), or when
   * this alternation is a group's body sitting at an eligible edge of ITS
   * OWN parent sequence (see RegexBlockNode.vue/RegexSequenceEditor.vue).
   * Every branch of an eligible alternation is equally eligible: which
   * branch actually runs is mutually exclusive with the others, so ^/$ in
   * one branch doesn't need the other branches to also carry it.
   */
  startEligible?: boolean;
  endEligible?: boolean;
}>(), {
  startEligible: true,
  endEligible: true,
});

function addBranch(): void {
  props.alternation.branches.push(emptySequence());
}

function removeBranch(index: number): void {
  props.alternation.branches.splice(index, 1);
}
</script>

<template>
  <div class="wtf-regex-alternation">
    <template v-for="(branch, index) in alternation.branches" :key="branch.key">
      <div v-if="index > 0" class="wtf-regex-alternation-or">OR</div>
      <div class="wtf-regex-alternation-branch">
        <RegexSequenceEditor :sequence="branch" :start-eligible="startEligible" :end-eligible="endEligible" />
        <BButton
          v-if="alternation.branches.length > 1"
          variant="link"
          size="sm"
          class="wtf-regex-alternation-remove-branch"
          title="Remove this alternative"
          @click="removeBranch(index)"
        >
          <FontAwesomeIcon :icon="faTrash" />
        </BButton>
      </div>
    </template>
    <BButton variant="outline-secondary" size="sm" class="wtf-regex-alternation-add" @click="addBranch">
      <FontAwesomeIcon :icon="faPlus" /> Add alternative (OR)
    </BButton>
  </div>
</template>
