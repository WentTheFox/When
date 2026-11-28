import type { GraphEdge, GraphNode, Point } from './types';

// Deterministic PRNG (mulberry32) so the same seed always produces the same sequence - used instead of
// Math.random() throughout the layout simulation so a given owner's graph looks the same on every reload.
/* eslint-disable no-bitwise -- bit-twiddling is inherent to this well-known PRNG */
export function mulberry32(seed: number): () => number {
  let state = seed;
  return () => {
    state |= 0;
    state = (state + 0x6d2b79f5) | 0;
    let t = Math.imul(state ^ (state >>> 15), 1 | state);
    t ^= (t + Math.imul(t ^ (t >>> 7), 61 | t));
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}
/* eslint-enable no-bitwise */

/**
 * Minimal force-directed layout: node repulsion, spring edges, weak center gravity (only to stop isolated
 * nodes drifting off to infinity). Runs in an unbounded virtual space and returns the settled positions;
 * fitting them to a canvas is the caller's job (see viewport.ts).
 */
export function layoutGraph(nodes: GraphNode[], edges: GraphEdge[], seed: number): Map<string, Point> {
  const rng = mulberry32(seed || 1);
  const sim = nodes.map((n) => ({ id: n.id, x: (rng() - 0.5) * 200, y: (rng() - 0.5) * 200, vx: 0, vy: 0 }));
  const byId = new Map(sim.map((n) => [n.id, n]));
  const usable = edges.filter((e) => byId.has(e.from) && byId.has(e.to));

  const iterations = sim.length > 0 ? 300 : 0;
  for (let iter = 0; iter < iterations; iter++) {
    for (let i = 0; i < sim.length; i++) {
      for (let j = i + 1; j < sim.length; j++) {
        const a = sim[i]; const b = sim[j];
        let dx = a.x - b.x; let dy = a.y - b.y;
        let distSq = dx * dx + dy * dy;
        if (distSq < 1) { dx = rng() - 0.5; dy = rng() - 0.5; distSq = 1; }
        const force = 4000 / distSq;
        const dist = Math.sqrt(distSq);
        const fx = (dx / dist) * force; const fy = (dy / dist) * force;
        a.vx += fx; a.vy += fy;
        b.vx -= fx; b.vy -= fy;
      }
    }
    usable.forEach((e) => {
      const a = byId.get(e.from)!; const b = byId.get(e.to)!;
      const dx = b.x - a.x; const dy = b.y - a.y;
      const dist = Math.sqrt(dx * dx + dy * dy) || 1;
      const force = (dist - 55) * 0.03;
      const fx = (dx / dist) * force; const fy = (dy / dist) * force;
      a.vx += fx; a.vy += fy;
      b.vx -= fx; b.vy -= fy;
    });
    sim.forEach((n) => {
      n.vx += -n.x * 0.0005;
      n.vy += -n.y * 0.0005;
      n.vx *= 0.86; n.vy *= 0.86;
      // Clamp per-step speed so one close-encounter spike can't fling a node far away and dominate the bounds.
      const speed = Math.sqrt(n.vx * n.vx + n.vy * n.vy);
      if (speed > 25) { n.vx = (n.vx / speed) * 25; n.vy = (n.vy / speed) * 25; }
      n.x += n.vx; n.y += n.vy;
    });
  }

  return new Map(sim.map((n) => [n.id, { x: n.x, y: n.y }]));
}

/** Focus node in the middle, its neighbours evenly on a ring (sorted by id so it is stable). */
export function radialLayout(focusId: string, neighbourIds: string[]): Map<string, Point> {
  const ids = [...neighbourIds].sort();
  const radius = Math.max(60, ids.length * 14);
  const out = new Map<string, Point>([[focusId, { x: 0, y: 0 }]]);
  ids.forEach((id, i) => {
    const angle = (i / ids.length) * Math.PI * 2 - Math.PI / 2;
    out.set(id, { x: Math.cos(angle) * radius, y: Math.sin(angle) * radius });
  });

  return out;
}
