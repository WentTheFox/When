/**
 * Single source of truth for "what is this block called, what does it do,
 * and what regex syntax does it correspond to" — read by BOTH
 * RegexVisualEditorModal.vue's draggable palette (label/hint/symbol shown
 * next to it) and RegexBlockNode.vue's canvas rendering (label/symbol shown
 * on a live block via blockKindOf()). Before this existed the two kept
 * their own separate copies of this text and could silently drift apart
 * (e.g. the palette saying "Literal text" while the canvas block for the
 * exact same node said just "Text") — every block's own name/description
 * now has exactly one place it's written down.
 */
import { emptyAlternation, newBlockKey, type RegexNode } from './regexAstModel';

/**
 * A block's own color, separate from its RegexNode `type` — every kind
 * maps 1:1 to a `type` EXCEPT capturing vs. non-capturing groups, which
 * are both `type: 'group'` but need to read as different colors (a real
 * capture group vs. a plain grouping construct matters enough here that
 * conflating them visually would undersell it) — same blue/grey split
 * regexHighlight.ts's own wtf-regex-tok-group/wtf-regex-tok-noncap
 * already use for the plain-text view of the same two constructs.
 */
export type BlockColorKey = 'literal' | 'charClass' | 'anchor' | 'group' | 'noncap' | 'raw';

export type BlockKind = {
  id: string;
  /** The RegexNode variant this kind produces/matches. */
  type: RegexNode['type'];
  /** Which wtf-regex-block-color-<key> class colors this kind, on both a palette entry and any canvas block of it — see BlockColorKey's own doc comment for why this isn't just `type`. */
  colorKey: BlockColorKey;
  /** Canonical display name — shown on the palette entry, and reused verbatim as the label on a matching canvas block wherever the canvas doesn't need a different inline phrasing (see RegexBlockNode.vue's own note on the one exception, the custom character class's "Any of [...]" builder text). */
  label: string;
  /**
   * The regex syntax this kind corresponds to, e.g. '\\d' or '(?:…)' —
   * shown as a highlighted code chip after the label (both in the palette
   * and on the canvas block itself). Omitted for kinds with no single
   * fixed syntax (literal text, raw regex). Uses the single Unicode
   * ellipsis character '…', not three literal dots '...' — the chip is
   * itself run through the real regex tokenizer (RegexHighlightedCode),
   * which would otherwise color each '.' as its own "any character"
   * token instead of the whole thing reading as plain placeholder text.
   */
  symbol?: string;
  /** One-line "what this does" — palette only, plain text (the symbol above already covers the syntax, so this never repeats it). */
  hint: string;
  /** Only false for a kind reachable by parsing an existing pattern but with no palette entry to drag it in from (currently just \B, "not a word boundary") — filtered out of BLOCK_PALETTE below but still needed so blockKindOf() can label it on the canvas. */
  paletteVisible?: boolean;
  /** True if `node` is a live instance of this kind — lets the canvas look up a node's own label/symbol by matching against this same list, instead of keeping a second hardcoded label table by hand (see blockKindOf()). */
  matches: (node: RegexNode) => boolean;
  factory: () => RegexNode;
};

export const BLOCK_KINDS: BlockKind[] = [
  {
    id: 'literal', type: 'literal', colorKey: 'literal', label: 'Literal text', hint: 'Matches this exact text',
    matches: (node) => node.type === 'literal',
    factory: () => ({ key: newBlockKey(), type: 'literal', text: '' }),
  },
  {
    id: 'digit', type: 'charClass', colorKey: 'charClass', label: 'Digit', symbol: '\\d', hint: 'Any digit 0-9',
    matches: (node) => node.type === 'charClass' && node.kind === 'digit',
    factory: () => ({ key: newBlockKey(), type: 'charClass', kind: 'digit' }),
  },
  {
    id: 'word', type: 'charClass', colorKey: 'charClass', label: 'Word character', symbol: '\\w', hint: 'Letter, digit, or underscore',
    matches: (node) => node.type === 'charClass' && node.kind === 'word',
    factory: () => ({ key: newBlockKey(), type: 'charClass', kind: 'word' }),
  },
  {
    id: 'whitespace', type: 'charClass', colorKey: 'charClass', label: 'Whitespace', symbol: '\\s', hint: 'Space, tab, newline',
    matches: (node) => node.type === 'charClass' && node.kind === 'whitespace',
    factory: () => ({ key: newBlockKey(), type: 'charClass', kind: 'whitespace' }),
  },
  {
    id: 'any', type: 'charClass', colorKey: 'charClass', label: 'Any character', symbol: '.', hint: 'Matches any single character',
    matches: (node) => node.type === 'charClass' && node.kind === 'any',
    factory: () => ({ key: newBlockKey(), type: 'charClass', kind: 'any' }),
  },
  {
    id: 'custom-class', type: 'charClass', colorKey: 'charClass', label: 'Custom character class', symbol: '[…]', hint: 'Any of a specific set of characters',
    matches: (node) => node.type === 'charClass' && node.kind === 'custom',
    factory: () => ({ key: newBlockKey(), type: 'charClass', kind: 'custom', custom: 'a-z' }),
  },
  {
    id: 'capture-group', type: 'group', colorKey: 'group', label: 'Capture group', symbol: '(…)', hint: 'Remembers what it matches',
    matches: (node) => node.type === 'group' && node.capturing,
    factory: () => ({ key: newBlockKey(), type: 'group', capturing: true, body: emptyAlternation() }),
  },
  {
    id: 'noncapture-group', type: 'group', colorKey: 'noncap', label: 'Group', symbol: '(?:…)', hint: 'Groups blocks together without capturing',
    matches: (node) => node.type === 'group' && !node.capturing,
    factory: () => ({ key: newBlockKey(), type: 'group', capturing: false, body: emptyAlternation() }),
  },
  {
    id: 'start-anchor', type: 'anchor', colorKey: 'anchor', label: 'Start of text', symbol: '^', hint: 'Matches only at the very start',
    matches: (node) => node.type === 'anchor' && node.kind === 'start',
    factory: () => ({ key: newBlockKey(), type: 'anchor', kind: 'start' }),
  },
  {
    id: 'end-anchor', type: 'anchor', colorKey: 'anchor', label: 'End of text', symbol: '$', hint: 'Matches only at the very end',
    matches: (node) => node.type === 'anchor' && node.kind === 'end',
    factory: () => ({ key: newBlockKey(), type: 'anchor', kind: 'end' }),
  },
  {
    id: 'word-boundary', type: 'anchor', colorKey: 'anchor', label: 'Word boundary', symbol: '\\b', hint: 'Edge of a word',
    matches: (node) => node.type === 'anchor' && node.kind === 'wordBoundary',
    factory: () => ({ key: newBlockKey(), type: 'anchor', kind: 'wordBoundary' }),
  },
  {
    id: 'non-word-boundary', type: 'anchor', colorKey: 'anchor', label: 'Not a word boundary', symbol: '\\B', hint: 'The opposite of a word boundary',
    paletteVisible: false,
    matches: (node) => node.type === 'anchor' && node.kind === 'nonWordBoundary',
    factory: () => ({ key: newBlockKey(), type: 'anchor', kind: 'nonWordBoundary' }),
  },
  {
    id: 'raw', type: 'raw', colorKey: 'raw', label: 'Raw regex', hint: 'Any regex syntax, typed directly',
    matches: (node) => node.type === 'raw',
    factory: () => ({ key: newBlockKey(), type: 'raw', text: '' }),
  },
];

/** Only the kinds a block can actually be dragged in as — see BlockKind.paletteVisible's own doc comment. */
export const BLOCK_PALETTE: BlockKind[] = BLOCK_KINDS.filter((kind) => kind.paletteVisible !== false);

/**
 * The BlockKind a live node is an instance of — every RegexNode a block
 * tree can actually contain (parsed or hand-built) matches exactly one
 * entry above, so this is never null in practice.
 */
export function blockKindOf(node: RegexNode): BlockKind | undefined {
  return BLOCK_KINDS.find((kind) => kind.matches(node));
}

/**
 * True if `candidate` — either a palette entry about to be cloned in (a
 * BlockKind, identified by `id`, since the RegexNode it would produce
 * doesn't exist yet) or an already-in-tree node being moved (a RegexNode,
 * identified by its own type/kind) — is a start/end anchor of the given
 * kind. Used by RegexSequenceEditor.vue's vuedraggable `:move` veto to
 * reject a ^/$ drop into a sequence that isn't start/end-eligible.
 */
export function isAnchorCandidate(candidate: RegexNode | BlockKind, kind: 'start' | 'end'): boolean {
  return 'id' in candidate ? candidate.id === `${kind}-anchor` : candidate.type === 'anchor' && candidate.kind === kind;
}
