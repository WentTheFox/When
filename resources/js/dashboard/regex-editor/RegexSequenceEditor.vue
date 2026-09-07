<script setup lang="ts">
/**
 * One "row" of concatenated blocks — a SequenceNode's own `items` array,
 * rendered as a <draggable> list so blocks can be reordered within it, or
 * dragged in/out of the palette or any other sequence in the tree (every
 * sequence editor everywhere in the modal shares the same drag `group`
 * name, see RegexVisualEditorModal.vue's own note on that).
 */
import draggable from 'vuedraggable';
import { computed, inject } from 'vue';
import RegexBlockNode from './RegexBlockNode.vue';
import { CAPTURE_GROUP_LIMIT_REACHED_KEY, INVALID_BRANCH_KEYS_KEY } from './regexEditorContext';
import { isAnchorCandidate, type BlockKind } from './blockPalette';
import { pinAnchorsToSequenceEdges, type RegexNode, type SequenceNode } from './regexAstModel';

const props = withDefaults(defineProps<{
  sequence: SequenceNode;
  /**
   * Whether a start-anchor (^) dropped into this exact sequence would
   * land somewhere it could actually mean "start of input" — true for the
   * root sequence, or a group's body when that group itself sits at an
   * eligible edge of ITS OWN parent sequence (threaded down from
   * RegexBlockNode.vue/RegexAlternationEditor.vue). A ^ makes sense
   * anywhere reachable this way (nested arbitrarily deep through a chain
   * of always-first groups), not only at the very top level.
   */
  startEligible?: boolean;
  endEligible?: boolean;
}>(), {
  startEligible: true,
  endEligible: true,
});

function removeAt(index: number): void {
  props.sequence.items.splice(index, 1);
}

function pinAnchorsToEdges(): void {
  pinAnchorsToSequenceEdges(props.sequence.items);
}

const captureGroupLimitReached = inject(CAPTURE_GROUP_LIMIT_REACHED_KEY);
const invalidBranchKeys = inject(INVALID_BRANCH_KEYS_KEY);
const isInvalidBranch = computed(() => invalidBranchKeys?.value.has(props.sequence.key) ?? false);

/**
 * Vetoes:
 * - a fresh Capture group dropped into this sequence once the field's own
 *   maxCaptureGroups cap is reached (see RegexVisualEditorModal.vue's own
 *   note on why the palette itself also disables that entry — this is the
 *   check that actually matters, since a `filter` on the palette's own
 *   draggable only stops *that* drag gesture from starting, not e.g. one
 *   already in flight);
 * - a start/end anchor dropped (or moved) into a sequence that isn't
 *   startEligible/endEligible (see those props' own doc comment) — ^/$
 *   anywhere else can never be satisfied.
 */
function onMove(evt: { draggedContext: { element: RegexNode | BlockKind } }): boolean {
  const dragged = evt.draggedContext.element;
  if ('id' in dragged && dragged.id === 'capture-group' && captureGroupLimitReached?.value) return false;
  if (!props.startEligible && isAnchorCandidate(dragged, 'start')) return false;
  if (!props.endEligible && isAnchorCandidate(dragged, 'end')) return false;
  return true;
}
</script>

<template>
  <draggable
    :list="sequence.items"
    item-key="key"
    group="regex-blocks"
    class="wtf-regex-sequence"
    :class="{ 'wtf-regex-sequence--empty': sequence.items.length === 0, 'wtf-regex-sequence--invalid': isInvalidBranch }"
    ghost-class="wtf-regex-block-ghost"
    :animation="150"
    :move="onMove"
    @change="pinAnchorsToEdges"
  >
    <template #item="{ element, index }">
      <RegexBlockNode
        :node="element"
        :start-eligible="startEligible && index === 0"
        :end-eligible="endEligible && index === sequence.items.length - 1"
        @remove="removeAt(index)"
      />
    </template>
    <template v-if="sequence.items.length === 0" #footer>
      <p class="wtf-regex-sequence-empty-label">Drag blocks here</p>
    </template>
  </draggable>
  <p v-if="isInvalidBranch" class="wtf-regex-sequence-invalid-label">This branch can never match anything.</p>
</template>
