/**
 * The subset of PatternPreview.vue's own props that describe *how a field's
 * pattern should be tested* (examples, mode, sample words, split pattern,
 * preceding-pattern cascade) as opposed to *what pattern it's currently
 * testing* (that's always the field's own live value, passed separately as
 * `pattern`). Pulled out to its own type so RegexPatternInput.vue can carry
 * one of these through to the visual editor modal (RegexVisualEditorModal.vue)
 * without redeclaring every individual prop — the modal then renders the
 * exact same PatternPreview component, with the exact same config, that
 * already sits next to the field on the page, rather than a second
 * hand-rolled preview.
 */
export type PatternPreviewConfig = {
  examples?: string[];
  placeholder?: string;
  /**
   * 'match': DND/nap/work/school/tentative/open-end/open-start — did it match at all?
   * 'extract': activity clause — what did group 1 capture, verbatim?
   * 'tokens': highlight clause — split group 1 on the split pattern, does any token contain a configured word (sampleWords)?
   * 'split': highlight name-split expression — highlights every resulting piece (no configured-word distinction — this field has no such concept), so an owner can see exactly how their clause gets divided.
   */
  mode: 'match' | 'extract' | 'tokens' | 'split';
  /** Used in 'tokens' mode only — stand-in for a share link's own configured highlight words. */
  sampleWords?: string[];
  /** Used in 'tokens'/'split' mode — the owner's highlight_split_pattern (or its default). */
  splitPattern?: string;
  /**
   * The Flag-pattern fields' own fixed processing order (Tentative,
   * Open-end, Open-start, Public — see IcsParser::stripTitleFlags) means
   * each one only ever sees a title AFTER every earlier pattern in that
   * order has already stripped its own marker. Passed here as every
   * earlier field's own current pattern value, in that same order, so
   * this field's 'match' preview reflects the title as it would actually
   * arrive by the time this pattern runs — not the raw, untouched example
   * line. Unused (and unnecessary) for every mode/field that isn't one of
   * those four.
   */
  precedingPatterns?: string[];
  /**
   * Which PatternPreview prop the field's own live pattern feeds while
   * being edited in the visual editor modal. Every field except
   * highlight_split_pattern matches directly against its own pattern
   * ('pattern', the default). highlight_split_pattern is a delimiter used
   * to split *another* field's capture, not matched directly — its own
   * on-page preview always tests a fixed `pattern="(.+)"` and feeds its
   * live value in as `splitPattern` instead, so the modal needs to do the
   * same substitution rather than wiring the live pattern to `pattern`.
   */
  livePatternTarget?: 'pattern' | 'splitPattern';
};

/**
 * Turns a field's own live pattern + its PatternPreviewConfig into the
 * actual props PatternPreview.vue needs — i.e. applies the `pattern` vs.
 * `splitPattern` substitution livePatternTarget describes. Shared by
 * PatternPreviewPanel.vue (used both directly on the Settings page and
 * inside RegexVisualEditorModal.vue) so that substitution is written once.
 */
export function resolvePatternPreviewProps(pattern: string, config: PatternPreviewConfig): PatternPreviewConfig & { pattern: string } {
  if (config.livePatternTarget === 'splitPattern') {
    return { ...config, pattern: '(.+)', splitPattern: pattern };
  }
  return { ...config, pattern };
}
