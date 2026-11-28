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
import SourceBadge from '../Components/SourceBadge.vue';
import { decryptString } from '../crypto';
import { useResolvedTheme } from '../composables/useTheme';
import { layoutGraph } from './graph/layout';
import { buildRelationshipEdges } from './graph/relationshipEdges';
import type { GraphEdge, GraphNode, GraphResponse } from './graph/types';
import { onVaultLockChange } from './onVaultLockChange';
import { useVault } from './useVault';
import { swatchByKey } from '../free/color-palette';

const canvas = ref<HTMLCanvasElement | null>(null);
const wrapper = ref<HTMLDivElement | null>(null);
const loadFailed = ref(false);
/** Decrypted source icon keys by node id (only once the vault is unlocked). */
const icons = ref<Record<string, string>>({});
/** Where each source's round icon badge sits on the canvas, set on every render and laid over it as HTML. */
const badges = ref<{ id: string; icon: string; color_key: string | null; x: number; y: number; diameter: number }[]>([]);
const resolvedTheme = useResolvedTheme();

let lastData: GraphResponse | null = null;
/** The edges the server drew (connection to source), before any relationship edges are added. */
let serverEdges: GraphEdge[] = [];
const { getRecordKey, vaultUnlocked } = useVault();

/**
 * Connection-to-connection edges come from the relationship fields the owner chose for the graph. Those lists
 * are end-to-end encrypted, so once the vault is unlocked they are decrypted here and drawn as undirected
 * edges (one per pair, however many fields or sides list it). Until then only the source links show.
 */
async function addRelationshipEdges(): Promise<void> {
  const data = lastData;
  if (!data || !vaultUnlocked.value) return;

  data.edges = [...serverEdges, ...(await buildRelationshipEdges(data.field_values, getRecordKey))];
  await decryptIcons();
  render();
}

async function decryptIcons(): Promise<void> {
  const next: Record<string, string> = {};
  for (const node of lastData?.nodes ?? []) {
    if (node.type !== 'source' || !node.icon_ciphertext || !node.icon_key_id) continue;
    try {
      next[node.id] = await decryptString(await getRecordKey(node.icon_key_id), node.icon_ciphertext);
    } catch (error) {
      console.error(error);
    }
  }
  if (vaultUnlocked.value) icons.value = next;
}

const resetRelationshipEdges = () => {
  icons.value = {};
  if (!lastData) return;
  lastData.edges = serverEdges;
  render();
};

onVaultLockChange({
  unlocked: vaultUnlocked,
  run: () => void addRelationshipEdges(),
  cleanup: () => void resetRelationshipEdges(),
});

/** Same fallback WhenTheFox's own ColorPalette gives every other unset slot — this app's signature hue. */
function nodeColor(colorKey: string | null): string {
  const swatch = swatchByKey(colorKey ?? undefined) ?? swatchByKey('blue');
  const hex = swatch ? (resolvedTheme.value === 'dark' ? swatch.dark : swatch.light) : '#6181b6';
  return hex;
}

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

  interface SimNode extends GraphNode { x: number; y: number }
  const positions = layoutGraph(data.nodes, data.edges, data.seed);
  const nodes: SimNode[] = data.nodes.map((n) => ({ ...n, ...positions.get(n.id)! }));
  const byId = new Map(nodes.map((n) => [n.id, n]));
  const edges = data.edges.filter((e) => byId.has(e.from) && byId.has(e.to));

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
    const radius = Math.min(sourceMaxRadius, sourceRadius * (1 + Math.log2(degree + 1) * 0.25));
    // A source with an icon is drawn bigger so the icon is legible.
    return icons.value[n.id] ? Math.max(radius * 1.5, 9) : radius;
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
    // A source with an icon is drawn as an HTML badge instead; its canvas circle would peek out round the edge.
    if (n.type === 'source' && icons.value[n.id]) return;

    const r = nodeRadiusFor(n);
    ctx.beginPath();
    ctx.arc(n.x, n.y, r, 0, Math.PI * 2);
    ctx.fillStyle = nodeColor(n.color_key);
    ctx.fill();
    ctx.lineWidth = Math.max(0.75, edgeLineWidth);
    ctx.strokeStyle = '#fcfcfb';
    ctx.stroke();
  });

  // The icon badges (HTML, the same round badge as everywhere else) cover their sources' circles.
  badges.value = nodes.flatMap((n) => {
    const icon = icons.value[n.id];
    return n.type === 'source' && icon ? [{ id: n.id, icon, color_key: n.color_key, x: n.x, y: n.y, diameter: nodeRadiusFor(n) * 2 }] : [];
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
let resizeTimer: ReturnType<typeof setTimeout> | undefined;
/** How long the container must stay the same size before the graph is laid out and drawn again. */
const RESIZE_DEBOUNCE_MS = 150;

onMounted(async () => {
  try {
    const { data } = await axios.get<GraphResponse>('/dashboard/connections/graph');
    lastData = data;
    serverEdges = [...data.edges];
    render();
    void addRelationshipEdges();
  } catch (error) {
    console.error(error);
    loadFailed.value = true;
    return;
  }

  // The canvas fills its wrapper, which grows or shrinks with the card (e.g. when the availability widget beside
  // it finishes loading and makes the row taller). Re-fit once the size has settled; the layout pass is not cheap.
  if (wrapper.value) {
    resizeObserver = new ResizeObserver(() => {
      clearTimeout(resizeTimer);
      resizeTimer = setTimeout(render, RESIZE_DEBOUNCE_MS);
    });
    resizeObserver.observe(wrapper.value);
  }
});

onUnmounted(() => {
  clearTimeout(resizeTimer);
  resizeObserver?.disconnect();
});

// Re-render (not re-fetch) on theme changes so source node colors track the
// current light/dark swatch half without a network round-trip.
watch(resolvedTheme, () => render());
</script>

<template>
  <p v-if="loadFailed" class="text-danger mb-0 small">Failed to load connections graph.</p>
  <div v-else ref="wrapper" class="wtf-graph-fill">
    <canvas ref="canvas" class="wtf-graph-fill-canvas"></canvas>
    <div
      v-for="badge in badges"
      :key="badge.id"
      class="wtf-graph-badge"
      :style="{ left: `${badge.x}px`, top: `${badge.y}px`, width: `${badge.diameter}px`, height: `${badge.diameter}px`, fontSize: `${badge.diameter * 0.62}px` }"
    >
      <SourceBadge :icon-key="badge.icon" :color-key="badge.color_key" size="100%" />
    </div>
  </div>
</template>

<style scoped>
/* Takes whatever height the card gives it (at least 260px); the canvas is laid over it so it never props the card open. */
.wtf-graph-fill { position: relative; flex: 1 1 auto; min-height: 260px; }
.wtf-graph-badge { display: flex; position: absolute; transform: translate(-50%, -50%); pointer-events: none; border-radius: 50%; box-shadow: 0 0 0 1px var(--bs-body-bg); }
.wtf-graph-fill-canvas { position: absolute; inset: 0; width: 100%; height: 100%; }
</style>
