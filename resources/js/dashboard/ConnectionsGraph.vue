<script setup lang="ts">
/**
 * A canvas force-directed network of the owner's connections and the
 * sources they were met through — a close port of the source app's own
 * dashboard connections-graph widget (its own dashboard.ts's
 * renderConnectionsGraph), adapted to this app's E2EE boundary (only
 * ids and palette color KEYS ever cross the wire, never a name — see
 * ConnectionsGraphController) and its own theme-aware color palette
 * (source nodes are colored by their category's color_key, resolved to a
 * light/dark hex client-side; there's no per-source icon here, unlike the
 * reference widget — see the plan's node-styling decision).
 */
import axios from 'axios';
import { onMounted, onUnmounted, ref, watch } from 'vue';
import { useResolvedTheme } from '../composables/useTheme';
import { swatchByKey } from '../free/color-palette';

interface GraphNode { id: string; type: 'connection' | 'source'; color_key: string | null }
interface GraphEdge { from: string; to: string; kind: 'introduced' | 'mutual' }
interface GraphResponse { seed: number; nodes: GraphNode[]; edges: GraphEdge[] }

const canvas = ref<HTMLCanvasElement | null>(null);
const loadFailed = ref(false);
const resolvedTheme = useResolvedTheme();

let lastData: GraphResponse | null = null;

/** Same fallback WhenTheFox's own ColorPalette gives every other unset slot — this app's signature hue. */
function nodeColor(colorKey: string | null): string {
  const swatch = swatchByKey(colorKey ?? undefined) ?? swatchByKey('blue');
  const hex = swatch ? (resolvedTheme.value === 'dark' ? swatch.dark : swatch.light) : '#6181b6';
  return hex;
}

// Deterministic PRNG (mulberry32) so the same seed always produces the same sequence - used instead of
// Math.random() throughout the layout simulation so a given owner's graph looks the same on every reload.
/* eslint-disable no-bitwise -- bit-twiddling is inherent to this well-known PRNG */
function mulberry32(seed: number): () => number {
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

function render(): void {
  const el = canvas.value;
  const data = lastData;
  if (!el || !data) return;

  const dpr = window.devicePixelRatio || 1;
  const width = el.clientWidth || 400;
  const height = el.clientHeight || 260;
  el.width = width * dpr;
  el.height = height * dpr;
  const ctx = el.getContext('2d');
  if (!ctx) return;
  ctx.scale(dpr, dpr);

  const rng = mulberry32(data.seed || 1);

  interface SimNode extends GraphNode { x: number; y: number; vx: number; vy: number }
  // Layout runs in an unbounded virtual space - clamping positions to the canvas each step is what
  // made disconnected nodes pile up flush against the edges, producing a square/grid look. Instead,
  // let the simulation settle naturally, then fit the resulting bounding box into the canvas below.
  const nodes: SimNode[] = data.nodes.map((n) => ({
    ...n,
    x: (rng() - 0.5) * 200,
    y: (rng() - 0.5) * 200,
    vx: 0,
    vy: 0,
  }));
  const byId = new Map(nodes.map((n) => [n.id, n]));
  const edges = data.edges.filter((e) => byId.has(e.from) && byId.has(e.to));

  // Minimal force-directed layout: node repulsion, spring edges, weak center gravity (only to stop
  // isolated nodes drifting off to infinity, not to enforce even canvas coverage).
  const iterations = nodes.length > 0 ? 300 : 0;
  for (let iter = 0; iter < iterations; iter++) {
    for (let i = 0; i < nodes.length; i++) {
      for (let j = i + 1; j < nodes.length; j++) {
        const a = nodes[i]; const b = nodes[j];
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
    edges.forEach((e) => {
      const a = byId.get(e.from)!; const b = byId.get(e.to)!;
      const dx = b.x - a.x; const dy = b.y - a.y;
      const dist = Math.sqrt(dx * dx + dy * dy) || 1;
      const targetDist = 55;
      const force = (dist - targetDist) * 0.03;
      const fx = (dx / dist) * force; const fy = (dy / dist) * force;
      a.vx += fx; a.vy += fy;
      b.vx -= fx; b.vy -= fy;
    });
    nodes.forEach((n) => {
      n.vx += -n.x * 0.0005;
      n.vy += -n.y * 0.0005;
      n.vx *= 0.86; n.vy *= 0.86;
      // Clamp per-step speed so a single close-encounter repulsion spike can't fling one node far
      // away from the rest - that single outlier would otherwise dominate the bounding box below and
      // force every other (properly spread-out) node to be fit-scaled down into a tiny central clump.
      const speed = Math.sqrt(n.vx * n.vx + n.vy * n.vy);
      const maxSpeed = 25;
      if (speed > maxSpeed) { n.vx = (n.vx / speed) * maxSpeed; n.vy = (n.vy / speed) * maxSpeed; }
      n.x += n.vx; n.y += n.vy;
    });
  }

  // Fit the settled layout's bounding box into the canvas with padding, preserving aspect ratio.
  const padding = 20;
  const xs = nodes.map((n) => n.x); const ys = nodes.map((n) => n.y);
  const minX = Math.min(...xs, 0); const maxX = Math.max(...xs, 0);
  const minY = Math.min(...ys, 0); const maxY = Math.max(...ys, 0);
  const spanX = Math.max(maxX - minX, 1); const spanY = Math.max(maxY - minY, 1);
  const scale = Math.min((width - padding * 2) / spanX, (height - padding * 2) / spanY, 4);
  const midX = (minX + maxX) / 2; const midY = (minY + maxY) / 2;
  nodes.forEach((n) => {
    n.x = width / 2 + (n.x - midX) * scale;
    n.y = height / 2 + (n.y - midY) * scale;
  });

  const nodeRadius = Math.max(2.5, Math.min(6, 6 * scale));
  const edgeLineWidth = Math.max(0.75, Math.min(1.5, 1.5 * scale));

  // A source ("met via" this) is drawn as a single hub node - growing with how many connections point
  // at it, rather than as a small dot repeated once per connection.
  const sourceRadius = Math.max(3.5, Math.min(8, 8 * scale));
  const sourceMaxRadius = sourceRadius * 1.6;
  const inDegreeById = new Map<string, number>();
  edges.forEach((e) => inDegreeById.set(e.to, (inDegreeById.get(e.to) ?? 0) + 1));
  const nodeRadiusFor = (n: SimNode): number => {
    if (n.type !== 'source') return nodeRadius;
    const degree = inDegreeById.get(n.id) ?? 0;
    return Math.min(sourceMaxRadius, sourceRadius * (1 + Math.log2(degree + 1) * 0.25));
  };

  ctx.clearRect(0, 0, width, height);

  // Pass 1: edge lines, under everything.
  edges.forEach((e) => {
    const a = byId.get(e.from)!; const b = byId.get(e.to)!;
    ctx.beginPath();
    ctx.moveTo(a.x, a.y);
    ctx.lineTo(b.x, b.y);
    ctx.strokeStyle = 'rgba(137,135,129,0.5)';
    ctx.lineWidth = edgeLineWidth;
    ctx.stroke();
  });

  // Pass 2: node circles, on top of lines.
  nodes.forEach((n) => {
    const r = nodeRadiusFor(n);
    ctx.beginPath();
    ctx.arc(n.x, n.y, r, 0, Math.PI * 2);
    ctx.fillStyle = nodeColor(n.color_key);
    ctx.fill();
    ctx.lineWidth = Math.max(0.75, edgeLineWidth);
    ctx.strokeStyle = '#fcfcfb';
    ctx.stroke();
  });

  // Pass 3: "introduced" arrowheads (connection -> source), on top of everything so they're never
  // hidden under a node. Plain connection<->connection edges carry no directionality in this schema
  // (unlike the reference app's edges table) so they never get one.
  const arrowLen = Math.max(3, Math.min(7, 7 * scale));
  edges.forEach((e) => {
    if (e.kind !== 'introduced') return;
    const a = byId.get(e.from)!; const b = byId.get(e.to)!;
    const dx = b.x - a.x; const dy = b.y - a.y;
    const dist = Math.sqrt(dx * dx + dy * dy) || 1;
    const ux = dx / dist; const uy = dy / dist;
    const tipX = b.x - ux * (nodeRadiusFor(b) + arrowLen); const tipY = b.y - uy * (nodeRadiusFor(b) + arrowLen);
    const angle = Math.atan2(dy, dx);
    ctx.beginPath();
    ctx.moveTo(tipX, tipY);
    ctx.lineTo(tipX - arrowLen * Math.cos(angle - Math.PI / 6), tipY - arrowLen * Math.sin(angle - Math.PI / 6));
    ctx.lineTo(tipX - arrowLen * Math.cos(angle + Math.PI / 6), tipY - arrowLen * Math.sin(angle + Math.PI / 6));
    ctx.closePath();
    ctx.fillStyle = 'rgba(82,81,78,0.9)';
    ctx.fill();
  });
}

let resizeObserver: ResizeObserver | null = null;

onMounted(async () => {
  try {
    const { data } = await axios.get<GraphResponse>('/dashboard/connections/graph');
    lastData = data;
    render();
  } catch (error) {
    console.error(error);
    loadFailed.value = true;
    return;
  }

  if (canvas.value) {
    resizeObserver = new ResizeObserver(() => render());
    resizeObserver.observe(canvas.value);
  }
});

onUnmounted(() => resizeObserver?.disconnect());

// Re-render (not re-fetch) on theme changes so source node colors track the
// current light/dark swatch half without a network round-trip.
watch(resolvedTheme, () => render());
</script>

<template>
  <p v-if="loadFailed" class="text-danger mb-0 small">Failed to load connections graph.</p>
  <canvas v-else ref="canvas" style="width:100%;height:260px"></canvas>
</template>
