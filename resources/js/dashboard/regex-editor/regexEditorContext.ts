import type { ComputedRef, InjectionKey } from 'vue';

/**
 * Provided once by RegexVisualEditorModal.vue, injected by every
 * RegexSequenceEditor.vue instance in the tree (however deeply nested
 * inside group bodies) — whether the field's own maxCaptureGroups cap
 * (see regexEditorModal.ts) has already been reached, so a sequence can
 * veto a *new* capture-group block being dropped into it from the palette
 * without every intermediate component needing to thread the value through
 * as a prop.
 */
export const CAPTURE_GROUP_LIMIT_REACHED_KEY: InjectionKey<ComputedRef<boolean>> = Symbol('regexCaptureGroupLimitReached');
