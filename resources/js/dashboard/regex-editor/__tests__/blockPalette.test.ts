import { describe, expect, it } from 'vitest';
import { BLOCK_KINDS, isAnchorCandidate } from '../blockPalette';
import { newBlockKey, type AnchorNode, type LiteralNode } from '../regexAstModel';

function paletteEntry(id: string) {
  const entry = BLOCK_KINDS.find((kind) => kind.id === id);
  if (!entry) throw new Error(`no such BLOCK_KINDS entry: ${id}`);
  return entry;
}

function anchorNode(kind: AnchorNode['kind']): AnchorNode {
  return { key: newBlockKey(), type: 'anchor', kind };
}

function literalNode(): LiteralNode {
  return { key: newBlockKey(), type: 'literal', text: 'foo' };
}

describe('isAnchorCandidate', () => {
  it('recognizes the start-anchor palette entry as a start candidate', () => {
    expect(isAnchorCandidate(paletteEntry('start-anchor'), 'start')).toBe(true);
    expect(isAnchorCandidate(paletteEntry('start-anchor'), 'end')).toBe(false);
  });

  it('recognizes the end-anchor palette entry as an end candidate', () => {
    expect(isAnchorCandidate(paletteEntry('end-anchor'), 'end')).toBe(true);
    expect(isAnchorCandidate(paletteEntry('end-anchor'), 'start')).toBe(false);
  });

  it('does not treat an unrelated palette entry (e.g. capture-group) as an anchor candidate', () => {
    expect(isAnchorCandidate(paletteEntry('capture-group'), 'start')).toBe(false);
    expect(isAnchorCandidate(paletteEntry('capture-group'), 'end')).toBe(false);
  });

  it('recognizes an in-tree start-anchor node being moved', () => {
    expect(isAnchorCandidate(anchorNode('start'), 'start')).toBe(true);
    expect(isAnchorCandidate(anchorNode('start'), 'end')).toBe(false);
  });

  it('recognizes an in-tree end-anchor node being moved', () => {
    expect(isAnchorCandidate(anchorNode('end'), 'end')).toBe(true);
    expect(isAnchorCandidate(anchorNode('end'), 'start')).toBe(false);
  });

  it('does not treat a word-boundary anchor as a start/end candidate', () => {
    expect(isAnchorCandidate(anchorNode('wordBoundary'), 'start')).toBe(false);
    expect(isAnchorCandidate(anchorNode('wordBoundary'), 'end')).toBe(false);
  });

  it('does not treat an unrelated in-tree node as an anchor candidate', () => {
    expect(isAnchorCandidate(literalNode(), 'start')).toBe(false);
    expect(isAnchorCandidate(literalNode(), 'end')).toBe(false);
  });
});
