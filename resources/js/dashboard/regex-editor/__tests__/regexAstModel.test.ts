import { describe, expect, it } from 'vitest';
import {
  countCapturingGroups,
  emptyAlternation,
  newBlockKey,
  parsePatternToAst,
  pinAnchorsToSequenceEdges,
  type AnchorNode,
  type LiteralNode,
  type RegexNode,
} from '../regexAstModel';

function literal(text: string): LiteralNode {
  return { key: newBlockKey(), type: 'literal', text };
}

function anchor(kind: AnchorNode['kind']): AnchorNode {
  return { key: newBlockKey(), type: 'anchor', kind };
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
