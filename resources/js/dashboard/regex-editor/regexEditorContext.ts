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

/**
 * Provided once by RegexVisualEditorModal.vue: the `key` of every branch
 * (SequenceNode, at any nesting depth) that findUnsatisfiableBranches()
 * (regexAstModel.ts) has proven can never match anything — a ^ or $ sits
 * somewhere content would have to come before/after it that can't
 * collapse to zero width. RegexSequenceEditor.vue injects this to
 * highlight its own sequence red when its key is a member.
 */
export const INVALID_BRANCH_KEYS_KEY: InjectionKey<ComputedRef<Set<string>>> = Symbol('regexInvalidBranchKeys');
