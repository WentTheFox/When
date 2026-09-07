<script setup lang="ts">
/**
 * One "row" of concatenated blocks — a SequenceNode's own `items` array,
 * rendered as a <draggable> list so blocks can be reordered within it, or
 * dragged in/out of the palette or any other sequence in the tree (every
 * sequence editor everywhere in the modal shares the same drag `group`
 * name, see RegexVisualEditorModal.vue's own note on that).
 */
import draggable from 'vuedraggable';
import { inject } from 'vue';
import RegexBlockNode from './RegexBlockNode.vue';
import { CAPTURE_GROUP_LIMIT_REACHED_KEY } from './regexEditorContext';
import type { BlockKind } from './blockPalette';
import { pinAnchorsToSequenceEdges, type RegexNode, type SequenceNode } from './regexAstModel';

const props = defineProps<{ sequence: SequenceNode }>();

function removeAt(index: number): void {
  props.sequence.items.splice(index, 1);
}

function pinAnchorsToEdges(): void {
  pinAnchorsToSequenceEdges(props.sequence.items);
}

const captureGroupLimitReached = inject(CAPTURE_GROUP_LIMIT_REACHED_KEY);

/**
 * Vetoes a fresh Capture group being dropped into this sequence once the
 * field's own maxCaptureGroups cap is reached (see RegexVisualEditorModal.vue's
 * own note on why the palette itself also disables that entry — this is
 * the check that actually matters, since a `filter` on the palette's own
 * draggable only stops *that* drag gesture from starting, not e.g. one
 * already in flight). `draggedContext.element` is a BlockKind only while
 * still coming straight from the palette (an in-tree move drags a real
 * RegexNode instead, which has no `id` field, so this never blocks moving
 * an existing group between sequences).
 */
function onMove(evt: { draggedContext: { element: RegexNode | BlockKind } }): boolean {
  const dragged = evt.draggedContext.element as Partial<BlockKind>;
  if (dragged.id === 'capture-group' && captureGroupLimitReached?.value) return false;
  return true;
}
</script>

<template>
  <draggable
    :list="sequence.items"
    item-key="key"
    group="regex-blocks"
    class="wtf-regex-sequence"
    :class="{ 'wtf-regex-sequence--empty': sequence.items.length === 0 }"
    ghost-class="wtf-regex-block-ghost"
    :animation="150"
    :move="onMove"
    @change="pinAnchorsToEdges"
  >
    <template #item="{ element, index }">
      <RegexBlockNode :node="element" @remove="removeAt(index)" />
    </template>
    <template v-if="sequence.items.length === 0" #footer>
      <p class="wtf-regex-sequence-empty-label">Drag blocks here</p>
    </template>
  </draggable>
</template>
