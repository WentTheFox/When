/**
 * Single global visual regex-block editor, mirroring confirmModal.ts's own
 * shape: requestRegexEdit() opens it pre-loaded with whatever pattern the
 * calling field currently holds, and resolves once it closes — the new
 * pattern (plus preview example text) on Apply, or null on Cancel/Esc/
 * backdrop-click (meaning "no change to either"). Shared by every
 * RegexPatternInput.vue instance (12 fields today) rather than one modal
 * instance per field.
 */
import { ref } from 'vue';
import type { PatternPreviewConfig } from './patternPreviewTypes';

export type RegexEditRequest = {
  pattern: string;
  /** Plain-text name of the field being edited (e.g. "DND event regular expression") — shown in the modal's own title so it's clear which of the 12 fields a pattern being built applies to, without needing to keep the page visible underneath. */
  fieldLabel: string;
  /** The same config already driving the PatternPreview rendered next to this field on the page — see patternPreviewTypes.ts. */
  previewConfig: PatternPreviewConfig;
  /** The field's own persisted preview example-lines override (its PatternPreview v-model), so the modal's preview starts from exactly the same example text, not the config's hardcoded default. */
  previewModelValue: string | null;
  /** Caps how many (…) capture groups the block tree may contain — mirrors App\Support\Regex::validateSingleCaptureGroup for highlight_clause_pattern/activity_clause_pattern (both capped at 1 server-side). Undefined means no cap, for every field with no such rule. */
  maxCaptureGroups?: number;
};

export type RegexEditResult = {
  pattern: string;
  previewModelValue: string | null;
};

export const regexEditorModalOpen = ref(false);
export const regexEditorModalPattern = ref('');
export const regexEditorModalFieldLabel = ref('');
export const regexEditorModalPreviewConfig = ref<PatternPreviewConfig>({ mode: 'match' });
export const regexEditorModalPreviewModelValue = ref<string | null>(null);
export const regexEditorModalMaxCaptureGroups = ref<number | undefined>(undefined);

let resolvers: ((result: RegexEditResult | null) => void)[] = [];

export function requestRegexEdit(request: RegexEditRequest): Promise<RegexEditResult | null> {
  regexEditorModalPattern.value = request.pattern;
  regexEditorModalFieldLabel.value = request.fieldLabel;
  regexEditorModalPreviewConfig.value = request.previewConfig;
  regexEditorModalPreviewModelValue.value = request.previewModelValue;
  regexEditorModalMaxCaptureGroups.value = request.maxCaptureGroups;
  regexEditorModalOpen.value = true;

  return new Promise((resolve) => resolvers.push(resolve));
}

/** Called by RegexVisualEditorModal.vue whenever it closes, regardless of why. */
export function settleRegexEditRequests(result: RegexEditResult | null): void {
  const pending = resolvers;
  resolvers = [];
  pending.forEach((resolve) => resolve(result));
}
