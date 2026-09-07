<script setup lang="ts">
/**
 * Renders one block of any RegexNode variant (regexAstModel.ts) plus its
 * own delete handle and (where applicable) quantifier control. `group`
 * recurses into RegexAlternationEditor.vue for its body — the only place
 * this whole block tree actually recurses.
 *
 * Deliberately mutates `props.node` in place rather than emitting
 * update:node/patch events for every keystroke — `node` is always a plain
 * object living inside the single reactive AlternationNode tree
 * RegexVisualEditorModal.vue owns (see that file), so writing straight
 * into it is both simpler and exactly as reactive as an emit-based round
 * trip would be, without the boilerplate of one v-model per field per
 * block type.
 */
import { faGripVertical, faTrash } from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome';
import { BButton } from 'bootstrap-vue-next';
import { computed } from 'vue';
import RegexHighlightedCode from '../RegexHighlightedCode.vue';
import { blockKindOf } from './blockPalette';
import type { CharClassNode, GroupNode, LiteralNode, RawNode, RegexNode } from './regexAstModel';
import RegexAlternationEditor from './RegexAlternationEditor.vue';
import RegexQuantifierControl from './RegexQuantifierControl.vue';

const props = defineProps<{ node: RegexNode }>();
defineEmits<{ remove: [] }>();

/** blockKindOf() only ever returns undefined for a node shape with no BLOCK_KINDS entry — unreachable here, since every RegexNode variant this tree can actually contain (parsed or hand-built via the palette) has exactly one. */
const kind = computed(() => blockKindOf(props.node)!);

/*
 * Each label span below carries its own wtf-regex-label-color-<colorKey>
 * class directly, rather than one wtf-regex-block-color-<colorKey> class
 * on this whole block and a `.wtf-regex-block-color-X .wtf-regex-block-label`
 * descendant-selector rule in CSS — a group's own body renders its child
 * blocks' labels as *DOM descendants* of the group's wrapper element, so a
 * descendant selector keyed off the group's own color class would match
 * (and, depending on stylesheet order, could win over) those children's
 * own color rules too, painting an unrelated nested block the group's
 * color. Coloring the label element itself sidesteps that entirely.
 */


function asLiteral(n: RegexNode): LiteralNode {
  return n as LiteralNode;
}
function asCharClass(n: RegexNode): CharClassNode {
  return n as CharClassNode;
}
function asGroup(n: RegexNode): GroupNode {
  return n as GroupNode;
}
function asRaw(n: RegexNode): RawNode {
  return n as RawNode;
}
</script>

<template>
  <div class="wtf-regex-block" :class="`wtf-regex-block-${node.type}`">
    <div class="wtf-regex-block-row">
    <span class="wtf-regex-block-handle" title="Drag to move">
      <FontAwesomeIcon :icon="faGripVertical" />
    </span>

    <template v-if="node.type === 'literal'">
      <span class="wtf-regex-block-label" :class="`wtf-regex-label-color-${kind.colorKey}`">{{ kind.label }}</span>
      <input
        v-model="asLiteral(node).text"
        type="text"
        class="form-control form-control-sm wtf-regex-block-text"
        placeholder="exact text…"
        aria-label="Literal text"
      >
    </template>

    <!-- Custom character class is the one kind whose canvas wording deliberately
         doesn't reuse kind.label ("Custom character class") verbatim — "Any of
         [not] [ ... ]" reads as a sentence around the bracket editor, where the
         categorical name wouldn't. -->
    <template v-else-if="node.type === 'charClass' && node.kind === 'custom'">
      <span class="wtf-regex-block-label" :class="`wtf-regex-label-color-${kind.colorKey}`">Any of</span>
      <label class="wtf-regex-block-negate">
        <input v-model="asCharClass(node).negated" type="checkbox">
        not
      </label>
      <span class="wtf-regex-block-bracket">[</span>
      <input
        v-model="asCharClass(node).custom"
        type="text"
        class="form-control form-control-sm wtf-regex-block-text"
        placeholder="a-z0-9_"
        aria-label="Character class contents"
      >
      <span class="wtf-regex-block-bracket">]</span>
    </template>

    <template v-else-if="node.type === 'charClass'">
      <span class="wtf-regex-block-label" :class="`wtf-regex-label-color-${kind.colorKey}`">{{ kind.label }}</span>
      <RegexHighlightedCode v-if="kind.symbol" :pattern="kind.symbol" />
      <label v-if="node.kind !== 'any'" class="wtf-regex-block-negate">
        <input v-model="asCharClass(node).negated" type="checkbox">
        not
      </label>
    </template>

    <template v-else-if="node.type === 'anchor'">
      <span class="wtf-regex-block-label" :class="`wtf-regex-label-color-${kind.colorKey}`">{{ kind.label }}</span>
      <RegexHighlightedCode v-if="kind.symbol" :pattern="kind.symbol" />
    </template>

    <template v-else-if="node.type === 'group'">
      <span class="wtf-regex-block-label" :class="`wtf-regex-label-color-${kind.colorKey}`">{{ kind.label }}</span>
      <RegexHighlightedCode v-if="kind.symbol" :pattern="kind.symbol" />
    </template>

    <template v-else-if="node.type === 'raw'">
      <span class="wtf-regex-block-label" :class="`wtf-regex-label-color-${kind.colorKey}`" title="Regex syntax with no visual-block equivalent, kept as-is">{{ kind.label }}</span>
      <input
        v-model="asRaw(node).text"
        type="text"
        class="form-control form-control-sm wtf-regex-block-text wtf-regex-block-raw-text"
        placeholder="e.g. (?=bar)"
        aria-label="Raw regex syntax"
      >
    </template>

    <RegexQuantifierControl
      v-if="node.type !== 'anchor'"
      v-model="(node as LiteralNode | CharClassNode | GroupNode | RawNode).quantifier"
    />

    <BButton variant="link" size="sm" class="wtf-regex-block-remove" title="Remove this block" @click="$emit('remove')">
      <FontAwesomeIcon :icon="faTrash" />
    </BButton>
    </div>

    <RegexAlternationEditor
      v-if="node.type === 'group'"
      :alternation="asGroup(node).body"
      class="wtf-regex-block-group-body"
    />
  </div>
</template>
