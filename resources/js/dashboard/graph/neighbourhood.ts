import type { GraphEdge, GraphNode } from './types';

/** The focused node, everything directly linked to it, and only the edges that touch it. */
export function focusSubgraph(nodes: GraphNode[], edges: GraphEdge[], focusId: string): { nodes: GraphNode[]; edges: GraphEdge[] } {
  const known = new Set(nodes.map((n) => n.id));
  if (!known.has(focusId)) return { nodes: [], edges: [] };

  const touching = edges.filter((e) => (e.from === focusId || e.to === focusId) && known.has(e.from) && known.has(e.to));
  const keep = new Set<string>([focusId]);
  touching.forEach((e) => { keep.add(e.from); keep.add(e.to); });

  return { nodes: nodes.filter((n) => keep.has(n.id)), edges: touching };
}
