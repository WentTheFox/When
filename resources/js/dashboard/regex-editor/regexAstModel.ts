/**
 * Two-way bridge between a plain pattern *string* (the bare, delimiter-free
 * fragment every RegexPatternInput.vue field stores — see that file's own
 * header comment) and the block tree RegexVisualEditorModal.vue actually
 * edits. `regexpp` only parses — parsePatternToAst() walks its AST once, on
 * open, into our own Node tree below; from then on the block tree is the
 * single source of truth, and serializeAst() is a hand-written inverse.
 *
 * Every field here is always matched with PCRE's `iu` flags (see
 * App\Support\Regex::compiles) — parsing with regexpp's `unicode: true`
 * keeps constructs that are only valid under `u` (e.g. \u{1F600},
 * \p{...}) parseable the same way the server would actually run them.
 */
import { RegExpParser } from 'regexpp';
import type * as AST from 'regexpp/ast';

/**
 * Every node/branch carries a synthetic `key`, unrelated to anything in the
 * regex itself — purely so <draggable>'s (vuedraggable/SortableJS) list
 * diffing has a stable identity per item across drags/reorders/edits.
 * Never read by serializeAst().
 */
let nextKey = 0;
export function newBlockKey(): string {
  nextKey += 1;
  return `blk${nextKey}`;
}

export type QuantifierMod = {
  kind: '*' | '+' | '?' | 'range';
  min?: number;
  /** null = unbounded (the {n,} form). Only meaningful for kind 'range'. */
  max?: number | null;
  greedy: boolean;
};

export type LiteralNode = { key: string; type: 'literal'; text: string; quantifier?: QuantifierMod };
export type CharClassNode = {
  key: string;
  type: 'charClass';
  kind: 'digit' | 'word' | 'whitespace' | 'any' | 'custom';
  negated?: boolean;
  /** kind 'custom' only — the raw content between [ and ], e.g. "a-z0-9_". */
  custom?: string;
  quantifier?: QuantifierMod;
};
export type AnchorNode = { key: string; type: 'anchor'; kind: 'start' | 'end' | 'wordBoundary' | 'nonWordBoundary' };
export type GroupNode = { key: string; type: 'group'; capturing: boolean; body: AlternationNode; quantifier?: QuantifierMod };
/** Anything with no block form here (lookaround, backreferences, named groups, unicode property escapes, ...) — kept verbatim so nothing is ever silently dropped or corrupted. */
export type RawNode = { key: string; type: 'raw'; text: string; quantifier?: QuantifierMod };

export type RegexNode = LiteralNode | CharClassNode | AnchorNode | GroupNode | RawNode;

export type SequenceNode = { key: string; type: 'sequence'; items: RegexNode[] };
export type AlternationNode = { type: 'alternation'; branches: SequenceNode[] };

export function emptySequence(): SequenceNode {
  return { key: newBlockKey(), type: 'sequence', items: [] };
}

export function emptyAlternation(): AlternationNode {
  return { type: 'alternation', branches: [emptySequence()] };
}

/**
 * ^ only ever makes sense at the very start of whatever sequence it's in,
 * $ only at the very end — rather than let a drop land anywhere and leave
 * an owner to notice and fix it, RegexSequenceEditor.vue calls this on
 * every change to its own sequence (a drag in/out, or a reorder within it)
 * to re-pin any start/end anchor already there back to its edge. Mutates
 * `items` in place (splice, to match how vuedraggable's own :list binding
 * already mutates the same array) and is a no-op when nothing moved a
 * pinned anchor out of place, so it's safe to call unconditionally.
 */
export function pinAnchorsToSequenceEdges(items: RegexNode[]): void {
  const startIndex = items.findIndex((node) => node.type === 'anchor' && node.kind === 'start');
  if (startIndex > 0) {
    items.unshift(items.splice(startIndex, 1)[0]!);
  }
  const endIndex = items.findIndex((node) => node.type === 'anchor' && node.kind === 'end');
  if (endIndex !== -1 && endIndex !== items.length - 1) {
    items.push(items.splice(endIndex, 1)[0]!);
  }
}

/** Every real (…) capture group anywhere in the tree, at any nesting depth — mirrors what App\Support\Regex::validateSingleCaptureGroup counts server-side, so the editor can enforce the same per-field limit (see RegexPatternInput.vue's maxCaptureGroups prop) before a save round-trip ever has to reject it. */
export function countCapturingGroups(alternation: AlternationNode): number {
  let count = 0;
  for (const branch of alternation.branches) {
    for (const item of branch.items) {
      if (item.type === 'group') {
        if (item.capturing) count += 1;
        count += countCapturingGroups(item.body);
      }
    }
  }
  return count;
}

function wholePatternAsRaw(pattern: string): AlternationNode {
  return { type: 'alternation', branches: [{ key: newBlockKey(), type: 'sequence', items: [{ key: newBlockKey(), type: 'raw', text: pattern }] }] };
}

function quantifierFromAst(q: AST.Quantifier): QuantifierMod {
  if (q.min === 0 && q.max === Infinity) return { kind: '*', greedy: q.greedy };
  if (q.min === 1 && q.max === Infinity) return { kind: '+', greedy: q.greedy };
  if (q.min === 0 && q.max === 1) return { kind: '?', greedy: q.greedy };
  return { kind: 'range', min: q.min, max: q.max === Infinity ? null : q.max, greedy: q.greedy };
}

/** Strips the outer [ ] / [^ ... ] delimiters regexpp's own `raw` includes, leaving just the class body — kept opaque rather than decomposed further (ranges/escapes inside a custom class aren't part of the requested block scope). */
function customClassBody(node: AST.CharacterClass): string {
  const inner = node.negate ? node.raw.slice(2, -1) : node.raw.slice(1, -1);
  return inner;
}

function mapQuantifiableElement(element: AST.QuantifiableElement): RegexNode {
  switch (element.type) {
    case 'Character':
      return { key: newBlockKey(), type: 'literal', text: String.fromCodePoint(element.value) };
    case 'CharacterClass':
      return { key: newBlockKey(), type: 'charClass', kind: 'custom', negated: element.negate, custom: customClassBody(element) };
    case 'CharacterSet':
      if (element.kind === 'any') return { key: newBlockKey(), type: 'charClass', kind: 'any' };
      if (element.kind === 'digit' || element.kind === 'word' || element.kind === 'space') {
        return {
          key: newBlockKey(),
          type: 'charClass',
          kind: element.kind === 'space' ? 'whitespace' : element.kind,
          negated: element.negate,
        };
      }
      // 'property' (\p{...}/\P{...}) — no block form, keep verbatim.
      return { key: newBlockKey(), type: 'raw', text: element.raw };
    case 'Group':
      return { key: newBlockKey(), type: 'group', capturing: false, body: mapAlternatives(element.alternatives) };
    case 'CapturingGroup':
      // A *named* group (?<name>...) carries information (the name) our
      // GroupNode has nowhere to put — falls back to raw rather than
      // silently discarding the name on serialize.
      if (element.name !== null) return { key: newBlockKey(), type: 'raw', text: element.raw };
      return { key: newBlockKey(), type: 'group', capturing: true, body: mapAlternatives(element.alternatives) };
    case 'Backreference':
      return { key: newBlockKey(), type: 'raw', text: element.raw };
    case 'Assertion':
      // The only Assertion that can appear as a QuantifiableElement is a
      // LookaheadAssertion (its own `type` field is literally "Assertion",
      // not "LookaheadAssertion" — that name only exists as a TS union
      // member, distinguished at runtime by `kind`) — no block form.
      return { key: newBlockKey(), type: 'raw', text: element.raw };
    default:
      return { key: newBlockKey(), type: 'raw', text: (element as AST.Node).raw };
  }
}

/** mapQuantifiableElement() never produces an AnchorNode (anchors can never be a QuantifiableElement in the parsed grammar), so its result always has somewhere to attach a quantifier. */
type QuantifiableRegexNode = Exclude<RegexNode, AnchorNode>;

function mapElement(element: AST.Element): RegexNode {
  if (element.type === 'Quantifier') {
    const inner = mapQuantifiableElement(element.element) as QuantifiableRegexNode;
    inner.quantifier = quantifierFromAst(element);
    return inner;
  }

  if (element.type === 'Assertion') {
    if (element.kind === 'start' || element.kind === 'end') {
      return { key: newBlockKey(), type: 'anchor', kind: element.kind };
    }
    if (element.kind === 'word') {
      return { key: newBlockKey(), type: 'anchor', kind: element.negate ? 'nonWordBoundary' : 'wordBoundary' };
    }
    // lookahead/lookbehind — no block form.
    return { key: newBlockKey(), type: 'raw', text: element.raw };
  }

  return mapQuantifiableElement(element);
}

function mapAlternatives(alternatives: AST.Alternative[]): AlternationNode {
  return {
    type: 'alternation',
    branches: alternatives.map((alt) => mapAlternative(alt)),
  };
}

/** Consecutive plain (unquantified) Characters collapse into one literal block — a quantified single char already arrives as its own Quantifier element, never merged with neighbors, so this never loses per-character quantifiers. */
function mapAlternative(alt: AST.Alternative): SequenceNode {
  const items: RegexNode[] = [];
  let literalBuffer = '';

  const flush = () => {
    if (literalBuffer) {
      items.push({ key: newBlockKey(), type: 'literal', text: literalBuffer });
      literalBuffer = '';
    }
  };

  for (const element of alt.elements) {
    if (element.type === 'Character') {
      literalBuffer += String.fromCodePoint(element.value);
      continue;
    }
    flush();
    items.push(mapElement(element));
  }
  flush();

  return { key: newBlockKey(), type: 'sequence', items };
}

export function parsePatternToAst(pattern: string): AlternationNode {
  if (pattern === '') return emptyAlternation();

  try {
    const parser = new RegExpParser({ ecmaVersion: 2020 });
    const ast = parser.parsePattern(pattern, 0, pattern.length, true);
    return mapAlternatives(ast.alternatives);
  } catch {
    // Should be unreachable in practice — every field already only ever
    // holds something App\Support\Regex::compiles already accepted — but
    // fails closed to "one big raw block" rather than throwing.
    return wholePatternAsRaw(pattern);
  }
}

/** Escapes a literal block's text so it always matches itself literally, whatever regex metacharacters it contains. */
export function escapeLiteralForRegex(text: string): string {
  return text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function quantifierSuffix(q: QuantifierMod): string {
  let base: string;
  switch (q.kind) {
    case '*':
    case '+':
    case '?':
      base = q.kind;
      break;
    default: {
      const min = q.min ?? 0;
      const max = q.max;
      base = max === null || max === undefined ? `{${min},}` : max === min ? `{${min}}` : `{${min},${max}}`;
    }
  }
  return q.greedy ? base : `${base}?`;
}

function serializeNode(node: RegexNode): string {
  switch (node.type) {
    case 'literal': {
      const escaped = escapeLiteralForRegex(node.text);
      if (!node.quantifier) return escaped;
      // A quantifier only ever binds to the single preceding atom — a
      // multi-character literal has to be grouped first so the quantifier
      // applies to the whole run, not just its last character.
      const atom = node.text.length === 1 ? escaped : `(?:${escaped})`;
      return atom + quantifierSuffix(node.quantifier);
    }
    case 'charClass': {
      let atom: string;
      if (node.kind === 'any') atom = '.';
      else if (node.kind === 'custom') atom = `[${node.negated ? '^' : ''}${node.custom ?? ''}]`;
      else {
        const letter = node.kind === 'digit' ? 'd' : node.kind === 'word' ? 'w' : 's';
        atom = `\\${node.negated ? letter.toUpperCase() : letter}`;
      }
      return node.quantifier ? atom + quantifierSuffix(node.quantifier) : atom;
    }
    case 'anchor':
      if (node.kind === 'start') return '^';
      if (node.kind === 'end') return '$';
      return node.kind === 'wordBoundary' ? '\\b' : '\\B';
    case 'group': {
      const inner = serializeAst(node.body);
      const atom = `(${node.capturing ? '' : '?:'}${inner})`;
      return node.quantifier ? atom + quantifierSuffix(node.quantifier) : atom;
    }
    case 'raw':
      return node.quantifier ? node.text + quantifierSuffix(node.quantifier) : node.text;
  }
}

export function serializeAst(ast: AlternationNode): string {
  return ast.branches
    .map((branch) => branch.items.map(serializeNode).join(''))
    .join('|');
}
