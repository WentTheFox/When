import { describe, expect, it } from 'vitest';
import { layoutGraph, radialLayout } from '../layout';
import { focusSubgraph } from '../neighbourhood';
import type { GraphEdge, GraphNode } from '../types';
import { fitTo, MAX_SCALE, MIN_SCALE, screenToWorld, worldToScreen, zoomAt } from '../viewport';

const node = (id: string, type: GraphNode['type'] = 'connection'): GraphNode => ({ id, type, color_key: null });
const nodes = [node('a'), node('b'), node('c'), node('d'), node('s', 'source')];
const edges: GraphEdge[] = [
  { from: 'a', to: 'b', kind: 'mutual' },
  { from: 'b', to: 'c', kind: 'mutual' },
  { from: 'a', to: 's', kind: 'introduced' },
];

describe('layoutGraph', () => {
  it('is deterministic for a seed and places every node', () => {
    const one = layoutGraph(nodes, edges, 42);
    const two = layoutGraph(nodes, edges, 42);
    expect([...one]).toEqual([...two]);
    expect(one.size).toBe(nodes.length);
  });
});

describe('radialLayout', () => {
  it('puts the focus at the origin and neighbours on a ring', () => {
    const out = radialLayout('a', ['c', 'b']);
    expect(out.get('a')).toEqual({ x: 0, y: 0 });
    const r = (id: string) => Math.hypot(out.get(id)!.x, out.get(id)!.y);
    expect(r('b')).toBeCloseTo(r('c'));
  });
});

describe('focusSubgraph', () => {
  it('keeps only the node, its direct neighbours and edges touching it', () => {
    const sub = focusSubgraph(nodes, edges, 'a');
    expect(sub.nodes.map((n) => n.id).sort()).toEqual(['a', 'b', 's']);
    expect(sub.edges).toHaveLength(2);
  });

  it('shows an isolated node alone and an unknown id as empty', () => {
    expect(focusSubgraph(nodes, edges, 'd').nodes.map((n) => n.id)).toEqual(['d']);
    expect(focusSubgraph(nodes, edges, 'zzz')).toEqual({ nodes: [], edges: [] });
  });
});

describe('viewport', () => {
  it('keeps the world point under the cursor fixed while zooming', () => {
    const view = { scale: 1, tx: 10, ty: 20 };
    const cursor = { x: 100, y: 80 };
    const before = screenToWorld(view, cursor);
    const after = zoomAt(view, cursor, 2);
    expect(after.scale).toBe(2);
    const back = worldToScreen(after, before);
    expect(back.x).toBeCloseTo(cursor.x);
    expect(back.y).toBeCloseTo(cursor.y);
  });

  it('clamps scale', () => {
    expect(zoomAt({ scale: 1, tx: 0, ty: 0 }, { x: 0, y: 0 }, 1e6).scale).toBe(MAX_SCALE);
    expect(zoomAt({ scale: 1, tx: 0, ty: 0 }, { x: 0, y: 0 }, 1e-6).scale).toBe(MIN_SCALE);
  });

  it('fits points into the canvas, centred', () => {
    const view = fitTo([{ x: -50, y: 0 }, { x: 50, y: 0 }], { width: 500, height: 300 });
    const l = worldToScreen(view, { x: -50, y: 0 });
    const r = worldToScreen(view, { x: 50, y: 0 });
    expect((l.x + r.x) / 2).toBeCloseTo(250);
    expect(l.x).toBeGreaterThanOrEqual(0);
    expect(r.x).toBeLessThanOrEqual(500);
  });
});
