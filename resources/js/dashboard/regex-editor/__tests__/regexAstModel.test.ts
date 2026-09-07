import { describe, expect, it } from 'vitest';
import {
  countCapturingGroups,
  emptyAlternation,
  findUnsatisfiableBranches,
  newBlockKey,
  parsePatternToAst,
  pinAnchorsToSequenceEdges,
  type AlternationNode,
  type AnchorNode,
  type GroupNode,
  type LiteralNode,
  type RegexNode,
  type SequenceNode,
} from '../regexAstModel';

function literal(text: string): LiteralNode {
  return { key: newBlockKey(), type: 'literal', text };
}

function anchor(kind: AnchorNode['kind']): AnchorNode {
  return { key: newBlockKey(), type: 'anchor', kind };
}

function sequence(...items: RegexNode[]): SequenceNode {
  return { key: newBlockKey(), type: 'sequence', items };
}

function alternation(...branches: SequenceNode[]): AlternationNode {
  return { type: 'alternation', branches };
}

function group(capturing: boolean, body: AlternationNode): GroupNode {
  return { key: newBlockKey(), type: 'group', capturing, body };
}

describe('pinAnchorsToSequenceEdges', () => {
  it('leaves an already-correctly-placed start/end anchor untouched', () => {
    const items: RegexNode[] = [anchor('start'), literal('foo'), anchor('end')];
    const before = items.slice();
    pinAnchorsToSequenceEdges(items);
    expect(items).toEqual(before);
  });

  it('moves a start anchor dropped in the middle back to the front', () => {
    const start = anchor('start');
    const items: RegexNode[] = [literal('foo'), start, literal('bar')];
    pinAnchorsToSequenceEdges(items);
    expect(items[0]).toBe(start);
    expect(items.map((n) => (n.type === 'literal' ? n.text : n.type))).toEqual(['anchor', 'foo', 'bar']);
  });

  it('moves an end anchor dropped before an existing block back to the end', () => {
    const end = anchor('end');
    const items: RegexNode[] = [literal('foo'), end, literal('bar')];
    pinAnchorsToSequenceEdges(items);
    expect(items[items.length - 1]).toBe(end);
    expect(items.map((n) => (n.type === 'literal' ? n.text : n.type))).toEqual(['foo', 'bar', 'anchor']);
  });

  it('pins both a start and an end anchor at once, independently', () => {
    const start = anchor('start');
    const end = anchor('end');
    const items: RegexNode[] = [literal('foo'), end, literal('bar'), start];
    pinAnchorsToSequenceEdges(items);
    expect(items[0]).toBe(start);
    expect(items[items.length - 1]).toBe(end);
    expect(items).toHaveLength(4);
  });

  it('does nothing to a sequence with no anchors', () => {
    const items: RegexNode[] = [literal('foo'), literal('bar')];
    const before = items.slice();
    pinAnchorsToSequenceEdges(items);
    expect(items).toEqual(before);
  });
});

describe('countCapturingGroups', () => {
  it('counts zero for a pattern with no capture groups', () => {
    expect(countCapturingGroups(parsePatternToAst('foo(?:bar)'))).toBe(0);
  });

  it('counts one real capture group, ignoring a non-capturing group alongside it', () => {
    expect(countCapturingGroups(parsePatternToAst('(?:a|b)(c)'))).toBe(1);
  });

  it('counts nested capture groups at any depth', () => {
    expect(countCapturingGroups(parsePatternToAst('((a)(b))'))).toBe(3);
  });

  it('counts capture groups across alternation branches', () => {
    const ast = emptyAlternation();
    ast.branches.push({ key: newBlockKey(), type: 'sequence', items: [] });
    ast.branches[0]!.items.push({ key: newBlockKey(), type: 'group', capturing: true, body: emptyAlternation() });
    ast.branches[1]!.items.push({ key: newBlockKey(), type: 'group', capturing: true, body: emptyAlternation() });
    expect(countCapturingGroups(ast)).toBe(2);
  });
});

describe('findUnsatisfiableBranches', () => {
  it('finds nothing wrong with ^ and $ each at the true edges of the pattern', () => {
    const branch = sequence(anchor('start'), literal('foo'), anchor('end'));
    expect(findUnsatisfiableBranches(alternation(branch))).toEqual(new Set());
  });

  it('flags a $ sitting in the middle of a branch, before required content', () => {
    const branch = sequence(literal('foo'), anchor('end'), literal('bar'));
    expect(findUnsatisfiableBranches(alternation(branch))).toEqual(new Set([branch.key]));
  });

  it('flags a ^ sitting after required content', () => {
    const branch = sequence(literal('foo'), anchor('start'));
    expect(findUnsatisfiableBranches(alternation(branch))).toEqual(new Set([branch.key]));
  });

  it('allows ^ just inside a group that is itself first in the pattern', () => {
    const innerBranch = sequence(anchor('start'), literal('foo'));
    const outerBranch = sequence(group(true, alternation(innerBranch)));
    expect(findUnsatisfiableBranches(alternation(outerBranch))).toEqual(new Set());
  });

  it('flags a $ just inside a group that is NOT last in the pattern', () => {
    const innerBranch = sequence(literal('foo'), anchor('end'));
    const outerBranch = sequence(group(true, alternation(innerBranch)), literal('bar'));
    expect(findUnsatisfiableBranches(alternation(outerBranch))).toEqual(new Set([innerBranch.key]));
  });

  it('does not flag the outer branch just because a nested branch is broken', () => {
    const innerBranch = sequence(literal('foo'), anchor('end'));
    const outerBranch = sequence(group(true, alternation(innerBranch)), literal('bar'));
    const invalid = findUnsatisfiableBranches(alternation(outerBranch));
    expect(invalid.has(outerBranch.key)).toBe(false);
  });

  it('treats an empty group ahead of ^ as not blocking it, since the group can match empty', () => {
    const emptyGroupBranch = sequence();
    const branch = sequence(group(false, alternation(emptyGroupBranch)), anchor('start'), literal('foo'));
    expect(findUnsatisfiableBranches(alternation(branch))).toEqual(new Set());
  });

  it('only flags the specific alternation branch that is actually broken', () => {
    const goodBranch = sequence(literal('foo'), anchor('end'));
    const badBranch = sequence(anchor('end'), literal('bar'));
    const invalid = findUnsatisfiableBranches(alternation(goodBranch, badBranch));
    expect(invalid).toEqual(new Set([badBranch.key]));
  });

  it('agrees with a real parsed pattern: ^foo$ is fine, foo$bar is not', () => {
    const goodAst = parsePatternToAst('^foo$');
    expect(findUnsatisfiableBranches(goodAst)).toEqual(new Set());

    const badAst = parsePatternToAst('foo$bar');
    expect(findUnsatisfiableBranches(badAst).size).toBeGreaterThan(0);
  });
});
