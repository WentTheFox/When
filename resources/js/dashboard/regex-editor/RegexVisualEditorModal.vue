<script setup lang="ts">
/**
 * The one visual regex-block editor for the whole app — mounted once (see
 * DashboardLayout.vue), opened via requestRegexEdit() (../regexEditorModal.ts)
 * from RegexPatternInput.vue's own "visual editor" button, on any of the 12
 * pattern fields. Same open/resolve shape as ConfirmModal.vue.
 *
 * The working state is a single reactive AlternationNode tree (`ast`) —
 * every child editor (RegexAlternationEditor/RegexSequenceEditor/
 * RegexBlockNode, all under ./) mutates it directly rather than emitting
 * patches up, since it's one shared reactive object for the whole tree
 * (see RegexBlockNode.vue's own note on why that's fine here). `livePattern`
 * just re-serializes it on every change for the Apply button and the
 * preview below.
 */
import { BBadge, BButton, BModal } from 'bootstrap-vue-next';
import { computed, provide, ref, watch } from 'vue';
import draggable from 'vuedraggable';
import { BLOCK_PALETTE, type BlockKind } from './blockPalette';
import PatternPreviewPanel from '../PatternPreviewPanel.vue';
import RegexHighlightedCode from '../RegexHighlightedCode.vue';
import { CAPTURE_GROUP_LIMIT_REACHED_KEY } from './regexEditorContext';
import {
  regexEditorModalFieldLabel,
  regexEditorModalMaxCaptureGroups,
  regexEditorModalOpen,
  regexEditorModalPattern,
  regexEditorModalPreviewConfig,
  regexEditorModalPreviewModelValue,
  settleRegexEditRequests,
} from '../regexEditorModal';
import { countCapturingGroups, parsePatternToAst, serializeAst, type AlternationNode, type RegexNode } from './regexAstModel';
import RegexAlternationEditor from './RegexAlternationEditor.vue';

const ast = ref<AlternationNode>(parsePatternToAst(''));
const livePattern = computed(() => serializeAst(ast.value));

const modalTitle = computed(() => (
  regexEditorModalFieldLabel.value ? `Visual regex editor — ${regexEditorModalFieldLabel.value}` : 'Visual regex editor'
));

const captureGroupCount = computed(() => countCapturingGroups(ast.value));
const captureGroupLimitReached = computed(() => (
  regexEditorModalMaxCaptureGroups.value !== undefined
  && captureGroupCount.value >= regexEditorModalMaxCaptureGroups.value
));
provide(CAPTURE_GROUP_LIMIT_REACHED_KEY, captureGroupLimitReached);

/**
 * The palette is itself a <draggable> (sort/put disabled — it's a source,
 * never a destination) whose `clone` hook is what vuedraggable actually
 * calls on drag-start to produce the real, independent RegexNode that
 * lands in whichever sequence the block gets dropped into (see
 * RegexSequenceEditor.vue's own note — the destination list never clones,
 * only the source does).
 */
function cloneFromPalette(entry: BlockKind): RegexNode {
  return entry.factory();
}

/**
 * A fresh Capture group block can't be started once the field's own
 * maxCaptureGroups cap is reached — checked here (the palette, the only
 * place a *new* one is ever created) via the entry's own id rather than
 * its produced node's shape, since the produced node doesn't exist yet.
 * RegexSequenceEditor.vue vetoes the actual drop with the same check
 * (via the injected captureGroupLimitReached), so this only needs to stop
 * the drag from a starting in the first place for a clean cursor/UX.
 */
function isPaletteEntryDisabled(entry: BlockKind): boolean {
  return entry.id === 'capture-group' && captureGroupLimitReached.value;
}

/** "0/1"-style badge shown in front of the Capture group entry's own hint line — null (no badge) for every other entry, and for Capture group itself on a field with no maxCaptureGroups cap at all. */
function captureGroupBadge(entry: BlockKind): string | null {
  if (entry.id !== 'capture-group' || regexEditorModalMaxCaptureGroups.value === undefined) return null;
  return `${captureGroupCount.value}/${regexEditorModalMaxCaptureGroups.value}`;
}

// Re-parse fresh every time the modal is (re)opened for a field, rather
// than continuously syncing with regexEditorModalPattern — once open, the
// block tree is the single source of truth until Apply/Cancel.
watch(regexEditorModalOpen, (open) => {
  if (open) {
    ast.value = parsePatternToAst(regexEditorModalPattern.value);
  }
});

function apply(): void {
  const pattern = livePattern.value;
  regexEditorModalOpen.value = false;
  settleRegexEditRequests({ pattern, previewModelValue: regexEditorModalPreviewModelValue.value });
}

function cancel(): void {
  regexEditorModalOpen.value = false;
}

// Fires on every close, whatever the trigger — apply() above already
// settled by the time this runs for that path (resolvers is emptied on
// first settle, so this is a harmless no-op then); for Cancel/Esc/
// backdrop-click it's the only settle, and must resolve with null (no
// change to either the pattern or the preview example text).
function onHide(): void {
  settleRegexEditRequests(null);
}
</script>

<template>
  <BModal
    v-model="regexEditorModalOpen"
    :title="modalTitle"
    size="xl"
    no-footer
    @hide="onHide"
  >
    <div class="wtf-regex-editor-modal">
      <div class="wtf-regex-editor-palette">
        <p class="wtf-regex-editor-palette-title">Drag a block in:</p>
        <draggable
          :list="BLOCK_PALETTE"
          item-key="id"
          :group="{ name: 'regex-blocks', pull: 'clone', put: false }"
          :sort="false"
          :clone="cloneFromPalette"
          filter=".wtf-regex-palette-block--disabled"
          class="wtf-regex-palette-list"
        >
          <template #item="{ element }">
            <div
              class="wtf-regex-block wtf-regex-palette-block"
              :class="[`wtf-regex-block-${element.type}`, `wtf-regex-block-color-${element.colorKey}`, { 'wtf-regex-palette-block--disabled': isPaletteEntryDisabled(element) }]"
              :title="isPaletteEntryDisabled(element) ? 'Capture group limit reached — remove one first' : undefined"
            >
              <span class="wtf-regex-palette-block-name">
                <span class="wtf-regex-block-label">{{ element.label }}</span>
                <RegexHighlightedCode v-if="element.symbol" :pattern="element.symbol" />
              </span>
              <span class="wtf-regex-palette-block-hint">
                <BBadge
                  v-if="captureGroupBadge(element)"
                  :variant="captureGroupLimitReached ? 'warning' : 'secondary'"
                  pill
                  class="wtf-regex-palette-block-badge"
                >{{ captureGroupBadge(element) }}</BBadge>
                {{ element.hint }}
              </span>
            </div>
          </template>
        </draggable>
      </div>

      <div class="wtf-regex-editor-canvas">
        <RegexAlternationEditor :alternation="ast" />

        <PatternPreviewPanel
          v-model:preview-model-value="regexEditorModalPreviewModelValue"
          :pattern="livePattern"
          :config="regexEditorModalPreviewConfig"
          :show-reset="false"
        />
      </div>
    </div>

    <div class="d-flex gap-2 mt-3">
      <BButton variant="primary" @click="apply">Apply</BButton>
      <BButton variant="outline-secondary" @click="cancel">Cancel</BButton>
    </div>
  </BModal>
</template>
