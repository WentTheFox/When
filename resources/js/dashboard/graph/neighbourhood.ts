import type { GraphEdge, GraphNode } from './types';

/**
 * The focused node(s), everything directly linked to any of them, and only the edges that touch one of them.
 * Several ids are a trail of nodes clicked through: each one's direct links stay visible.
 */
export function focusSubgraph(nodes: GraphNode[], edges: GraphEdge[], focus: string | string[]): { nodes: GraphNode[]; edges: GraphEdge[] } {
  const known = new Set(nodes.map((n) => n.id));
  const focused = new Set((Array.isArray(focus) ? focus : [focus]).filter((id) => known.has(id)));
  if (!focused.size) return { nodes: [], edges: [] };

  const touching = edges.filter((e) => (focused.has(e.from) || focused.has(e.to)) && known.has(e.from) && known.has(e.to));
  const keep = new Set<string>(focused);
  touching.forEach((e) => { keep.add(e.from); keep.add(e.to); });

  return { nodes: nodes.filter((n) => keep.has(n.id)), edges: touching };
}
