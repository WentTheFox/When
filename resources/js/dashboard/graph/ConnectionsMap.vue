<script setup lang="ts">
/**
 * The explorable connections map: the dashboard graph's data and layout, plus pan/zoom, hover tooltips,
 * names and profile pictures at higher zoom, and click-to-focus (a connection or a source, and only what it links to).
 *
 * §0.1: the server hands over only ids, color keys and ciphertext. Names are decrypted here once the vault is
 * unlocked and relationship edges are rebuilt from the decrypted bound fields; profile pictures are the
 * server-readable §0.2 URLs of the connection's preferred linked account.
 */
import { Link } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onMounted, onUnmounted, ref, shallowRef, watch } from 'vue';
import { MinusIcon, PlusIcon, ScanIcon, XIcon } from '@lucide/vue';
import AppIcon from '../../Components/AppIcon.vue';
import SourceBadge from '../../Components/SourceBadge.vue';
import { decryptString } from '../../crypto';
import { useResolvedTheme } from '../../composables/useTheme';
import { swatchByKey } from '../../free/color-palette';
import { buildConnectionsHash, buildConnectionsSearch } from '../connectionsHash';
import { identityPicture, type LinkedProfiles } from '../connectionIdentity';
import { onVaultLockChange } from '../onVaultLockChange';
import { useVault } from '../useVault';
import { layoutGraph, radialLayout } from './layout';
import { focusSubgraph } from './neighbourhood';
import { buildRelationshipEdges } from './relationshipEdges';
import type { GraphEdge, GraphNode, GraphResponse, Point } from './types';
import { fitTo, screenToWorld, worldToScreen, zoomAt, type View } from './viewport';

interface MapConnection { id: string; name_ciphertext: string; preferred_identity: string | null }
interface MapSource { id: string; category_id: string | null; name_ciphertext: string; icon_ciphertext: string | null }
interface MapCategory { id: string; icon_ciphertext: string | null }

const props = defineProps<{
  connections: MapConnection[];
  sources: MapSource[];
  categories: MapCategory[];
  profiles: Record<string, LinkedProfiles>;
}>();

/** Scales (world units to pixels) from which names, then pictures, are drawn. */
const LABEL_SCALE = 1.5;
const AVATAR_SCALE = 3;

const container = ref<HTMLDivElement | null>(null);
const canvas = ref<HTMLCanvasElement | null>(null);
const loadFailed = ref(false);
const loaded = ref(false);
const resolvedTheme = useResolvedTheme();
const { getRecordKey, vaultUnlocked } = useVault();

const data = shallowRef<GraphResponse | null>(null);
const serverEdges = shallowRef<GraphEdge[]>([]);
const relationshipEdges = shallowRef<GraphEdge[]>([]);
const names = ref<Record<string, string>>({});
/** Decrypted icon keys of sources and categories (palette icon, `flag:xx` or `brand:xx`). */
const icons = ref<Record<string, string>>({});
/** Bumped on every draw so the HTML icon badges laid over the canvas follow the view. */
const frame = ref(0);
/** The nodes clicked through so far, oldest first; the last is the focus. The map shows all of their direct links. */
const trail = ref<string[]>([]);
const focusId = computed(() => trail.value[trail.value.length - 1] ?? null);
const hover = ref<{ id: string; x: number; y: number } | null>(null);

let view: View = { scale: 1, tx: 0, ty: 0 };
/** The view to go back to when the focus is cleared. */
let savedView: View | null = null;
let userMoved = false;
let size = { width: 400, height: 400 };
let drawQueued = false;
let animationFrame = 0;

const allEdges = computed<GraphEdge[]>(() => [...serverEdges.value, ...relationshipEdges.value]);
const allNodes = computed<GraphNode[]>(() => data.value?.nodes ?? []);
const nodeById = computed(() => new Map(allNodes.value.map((n) => [n.id, n])));
const connectionById = computed(() => new Map(props.connections.map((c) => [c.id, c])));

/** What is on screen: everything, or the trail's nodes with all of their direct links. */
const active = computed(() => (trail.value.length ? focusSubgraph(allNodes.value, allEdges.value, trail.value) : { nodes: allNodes.value, edges: allEdges.value }));

const positions = computed<Map<string, Point>>(() => {
  if (!data.value) return new Map();
  if (!focusId.value) return layoutGraph(allNodes.value, allEdges.value, data.value.seed);

  if (trail.value.length > 1) return layoutGraph(active.value.nodes, active.value.edges, data.value.seed);

  const others = active.value.nodes.filter((n) => n.id !== focusId.value).map((n) => n.id);
  return radialLayout(focusId.value, others);
});

const degree = computed(() => {
  const counts = new Map<string, number>();
  allEdges.value.forEach((e) => {
    counts.set(e.from, (counts.get(e.from) ?? 0) + 1);
    counts.set(e.to, (counts.get(e.to) ?? 0) + 1);
  });
  return counts;
});

function nameOf(id: string): string {
  return names.value[id] ?? '';
}

const focusName = computed(() => (focusId.value ? nameOf(focusId.value) : ''));

function pictureOf(id: string): string | null {
  const connection = connectionById.value.get(id);
  return connection ? identityPicture(connection.preferred_identity, props.profiles[id] ?? {}) : null;
}

/** A source's own icon, else its category's. */
function iconOf(id: string): string | null {
  const source = props.sources.find((s) => s.id === id);

  return icons.value[id] ?? (source?.category_id ? icons.value[source.category_id] : undefined) ?? null;
}

/**
 * Sources with an icon get their round badge (the same one the Sources list uses, so flags, brands and every
 * palette icon draw identically) laid over the canvas circle. Pointer events pass through to the canvas.
 */
const badges = computed(() => {
  void frame.value;
  if (!loaded.value) return [];

  return active.value.nodes.flatMap((node) => {
    const icon = node.type === 'source' ? iconOf(node.id) : null;
    const at = positions.value.get(node.id);
    if (!icon || !at) return [];

    const s = worldToScreen(view, at);
    const r = radiusOf(node);
    if (s.x < -r || s.y < -r || s.x > size.width + r || s.y > size.height + r) return [];

    return [{ id: node.id, icon, color_key: node.color_key, x: s.x, y: s.y, diameter: r * 2 }];
  });
});

function colorOf(node: GraphNode): string {
  const swatch = swatchByKey(node.color_key ?? undefined) ?? swatchByKey('blue');
  return swatch ? (resolvedTheme.value === 'dark' ? swatch.dark : swatch.light) : '#6181b6';
}

const initialOf = (id: string) => (nameOf(id).trim()[0] ?? '?').toUpperCase();

/** Sources a connection was met through, for the tooltip. */
function sourcesOf(id: string): string[] {
  return allEdges.value
    .filter((e) => e.kind === 'introduced' && e.from === id)
    .map((e) => nameOf(e.to))
    .filter(Boolean);
}

const tooltip = computed(() => {
  if (!hover.value) return null;
  const node = nodeById.value.get(hover.value.id);
  if (!node) return null;

  const links = degree.value.get(node.id) ?? 0;
  return {
    x: hover.value.x,
    y: hover.value.y,
    title: nameOf(node.id) || (node.type === 'source' ? 'Source' : 'Connection (vault locked)'),
    kind: node.type === 'source' ? 'Source' : 'Connection',
    via: node.type === 'connection' ? sourcesOf(node.id) : [],
    links,
    picture: node.type === 'connection' ? pictureOf(node.id) : null,
    sourceIcon: node.type === 'source' ? iconOf(node.id) : null,
    colorKey: node.color_key,
  };
});

// ---- images ----------------------------------------------------------------------------------------------

const images = new Map<string, HTMLImageElement | 'failed'>();

function imageFor(url: string): HTMLImageElement | null {
  const cached = images.get(url);
  if (cached === 'failed') return null;
  if (cached) return cached.complete && cached.naturalWidth > 0 ? cached : null;

  const img = new Image();
  img.referrerPolicy = 'no-referrer';
  img.onload = () => scheduleDraw();
  img.onerror = () => images.set(url, 'failed');
  img.src = url;
  images.set(url, img);

  return null;
}

// ---- drawing ---------------------------------------------------------------------------------------------

/** Screen radius of a node: grows with zoom, but slower than the layout does. */
function radiusOf(node: GraphNode): number {
  const base = node.type === 'source' ? 9 : 7;
  const boost = node.id === focusId.value ? 1.35 : 1;
  return Math.max(3, base * Math.pow(view.scale, 0.75) * boost);
}

function scheduleDraw(): void {
  if (drawQueued) return;
  drawQueued = true;
  requestAnimationFrame(() => { drawQueued = false; draw(); });
}

function draw(): void {
  frame.value++;
  const el = canvas.value;
  const ctx = el?.getContext('2d');
  if (!el || !ctx) return;

  const dpr = window.devicePixelRatio || 1;
  if (el.width !== Math.round(size.width * dpr) || el.height !== Math.round(size.height * dpr)) {
    el.width = Math.round(size.width * dpr);
    el.height = Math.round(size.height * dpr);
  }
  ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  ctx.clearRect(0, 0, size.width, size.height);

  const dark = resolvedTheme.value === 'dark';
  const pos = positions.value;
  const screen = (id: string): Point | null => {
    const p = pos.get(id);
    return p ? worldToScreen(view, p) : null;
  };
  const inView = (p: Point, r: number) => p.x > -r && p.y > -r && p.x < size.width + r && p.y < size.height + r;

  ctx.lineCap = 'round';
  active.value.edges.forEach((e) => {
    const a = screen(e.from); const b = screen(e.to);
    if (!a || !b) return;
    const highlighted = hover.value && (e.from === hover.value.id || e.to === hover.value.id);
    ctx.beginPath();
    ctx.moveTo(a.x, a.y);
    ctx.lineTo(b.x, b.y);
    ctx.strokeStyle = highlighted ? (dark ? 'rgba(220,220,215,0.85)' : 'rgba(60,60,58,0.8)') : 'rgba(137,135,129,0.45)';
    ctx.lineWidth = highlighted ? 2 : Math.max(0.75, Math.min(2, view.scale * 0.6));
    ctx.stroke();
  });

  const showLabels = view.scale >= LABEL_SCALE;
  const showPictures = view.scale >= AVATAR_SCALE;
  const ring = dark ? '#1e1e1d' : '#fcfcfb';
  ctx.textAlign = 'center';
  ctx.textBaseline = 'top';
  ctx.font = '12px system-ui, sans-serif';

  active.value.nodes.forEach((node) => {
    const p = screen(node.id);
    if (!p) return;
    const r = radiusOf(node);
    if (!inView(p, r + 40)) return;

    const picture = showPictures && node.type === 'connection' ? pictureOf(node.id) : null;
    const img = picture ? imageFor(picture) : null;

    // A source with an icon is an HTML badge laid over the canvas; drawing its circle too would peek out round the edge.
    const hasBadge = node.type === 'source' && !!iconOf(node.id);

    if (!hasBadge) {
      ctx.beginPath();
      ctx.arc(p.x, p.y, r, 0, Math.PI * 2);
      ctx.fillStyle = colorOf(node);
      ctx.fill();
    }

    if (img) {
      ctx.save();
      ctx.beginPath();
      ctx.arc(p.x, p.y, r, 0, Math.PI * 2);
      ctx.clip();
      ctx.drawImage(img, p.x - r, p.y - r, r * 2, r * 2);
      ctx.restore();
    } else if (showPictures && node.type === 'connection' && nameOf(node.id)) {
      ctx.fillStyle = '#fff';
      ctx.textBaseline = 'middle';
      ctx.font = `600 ${Math.round(r)}px system-ui, sans-serif`;
      ctx.fillText(initialOf(node.id), p.x, p.y + 1);
      ctx.font = '12px system-ui, sans-serif';
      ctx.textBaseline = 'top';
    }

    if (!hasBadge) {
      ctx.beginPath();
      ctx.arc(p.x, p.y, r, 0, Math.PI * 2);
      ctx.lineWidth = trail.value.includes(node.id) || node.id === hover.value?.id ? 3 : 1.5;
      ctx.strokeStyle = trail.value.includes(node.id) ? (dark ? '#fff' : '#222') : ring;
      ctx.stroke();
    }

    const label = nameOf(node.id);
    if (label && (showLabels || trail.value.includes(node.id))) {
      ctx.lineWidth = 3;
      ctx.strokeStyle = dark ? 'rgba(30,30,29,0.9)' : 'rgba(252,252,251,0.9)';
      ctx.strokeText(label, p.x, p.y + r + 4);
      ctx.fillStyle = dark ? '#ecebe8' : '#2b2a28';
      ctx.fillText(label, p.x, p.y + r + 4);
    }
  });
}

// ---- view control ----------------------------------------------------------------------------------------

const reducedMotion = () => window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;

function setView(next: View, animate = true): void {
  cancelAnimationFrame(animationFrame);
  if (!animate || reducedMotion()) {
    view = next;
    scheduleDraw();
    return;
  }

  const from = view; const start = performance.now(); const duration = 250;
  const step = (now: number) => {
    const t = Math.min(1, (now - start) / duration);
    const e = 1 - Math.pow(1 - t, 3);
    view = { scale: from.scale + (next.scale - from.scale) * e, tx: from.tx + (next.tx - from.tx) * e, ty: from.ty + (next.ty - from.ty) * e };
    draw();
    if (t < 1) animationFrame = requestAnimationFrame(step);
  };
  animationFrame = requestAnimationFrame(step);
}

function fitView(animate = true): void {
  setView(fitTo([...positions.value.values()], size), animate);
}

function zoomBy(factor: number, around: Point = { x: size.width / 2, y: size.height / 2 }): void {
  userMoved = true;
  cancelAnimationFrame(animationFrame);
  view = zoomAt(view, around, factor);
  scheduleDraw();
}

function focusOn(id: string, animate = true): void {
  if (focusId.value === id) return;
  if (!trail.value.length) savedView = view;
  // Clicking a node already in the trail steps back to it; any other node extends the trail.
  const at = trail.value.indexOf(id);
  trail.value = at >= 0 ? trail.value.slice(0, at + 1) : [...trail.value, id];
  hover.value = null;
  fitView(animate);
}

function clearFocus(): void {
  if (!trail.value.length) return;
  trail.value = [];
  hover.value = null;
  if (savedView) setView(savedView, true); else fitView(true);
  savedView = null;
}

// ---- pointer interaction ---------------------------------------------------------------------------------

const pointers = new Map<number, Point>();
let dragFrom: Point | null = null;
let dragMoved = 0;
let pinchDistance = 0;

const local = (e: PointerEvent | WheelEvent): Point => {
  const rect = canvas.value!.getBoundingClientRect();
  return { x: e.clientX - rect.left, y: e.clientY - rect.top };
};

function hitTest(p: Point): GraphNode | null {
  const pos = positions.value;
  const nodes = active.value.nodes;
  for (let i = nodes.length - 1; i >= 0; i--) {
    const at = pos.get(nodes[i].id);
    if (!at) continue;
    const s = worldToScreen(view, at);
    const r = radiusOf(nodes[i]) + 3;
    if ((s.x - p.x) ** 2 + (s.y - p.y) ** 2 <= r * r) return nodes[i];
  }
  return null;
}

function onPointerDown(e: PointerEvent): void {
  canvas.value?.setPointerCapture(e.pointerId);
  pointers.set(e.pointerId, local(e));
  dragFrom = local(e);
  dragMoved = 0;
  if (pointers.size === 2) {
    const [a, b] = [...pointers.values()];
    pinchDistance = Math.hypot(a.x - b.x, a.y - b.y);
  }
}

function onPointerMove(e: PointerEvent): void {
  const p = local(e);

  if (pointers.has(e.pointerId)) {
    pointers.set(e.pointerId, p);

    if (pointers.size === 2) {
      const [a, b] = [...pointers.values()];
      const distance = Math.hypot(a.x - b.x, a.y - b.y);
      if (pinchDistance > 0) zoomBy(distance / pinchDistance, { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 });
      pinchDistance = distance;
      dragMoved = 99;
      return;
    }

    if (dragFrom) {
      const dx = p.x - dragFrom.x; const dy = p.y - dragFrom.y;
      dragMoved += Math.abs(dx) + Math.abs(dy);
      if (dragMoved > 4) {
        userMoved = true;
        cancelAnimationFrame(animationFrame);
        view = { ...view, tx: view.tx + dx, ty: view.ty + dy };
        dragFrom = p;
        hover.value = null;
        scheduleDraw();
      }
      return;
    }
  }

  const hit = hitTest(p);
  const next = hit ? { id: hit.id, x: p.x, y: p.y } : null;
  if (next?.id !== hover.value?.id || next) {
    hover.value = next;
    scheduleDraw();
  }
}

function onPointerUp(e: PointerEvent): void {
  const wasClick = pointers.size === 1 && dragMoved <= 4;
  pointers.delete(e.pointerId);
  pinchDistance = 0;
  dragFrom = pointers.size === 1 ? [...pointers.values()][0] : null;

  if (!wasClick) return;
  const hit = hitTest(local(e));
  if (hit) focusOn(hit.id);
}

function onLeave(): void {
  if (pointers.size > 0) return;
  hover.value = null;
  scheduleDraw();
}

function onWheel(e: WheelEvent): void {
  zoomBy(Math.exp(-e.deltaY * (e.ctrlKey ? 0.01 : 0.0015)), local(e));
}

function onKey(e: KeyboardEvent): void {
  if (e.key === 'Escape') clearFocus();
  else if (e.key === '+' || e.key === '=') zoomBy(1.4);
  else if (e.key === '-') zoomBy(1 / 1.4);
  else if (e.key === '0') fitView();
  else return;
  e.preventDefault();
}

// ---- data ------------------------------------------------------------------------------------------------

async function decryptNames(): Promise<void> {
  const next: Record<string, string> = {};
  for (const record of [...props.connections, ...props.sources]) {
    try {
      next[record.id] = await decryptString(await getRecordKey(record.id), record.name_ciphertext);
    } catch (error) {
      console.error(error);
    }
  }
  const nextIcons: Record<string, string> = {};
  for (const record of [...props.sources, ...props.categories]) {
    if (!record.icon_ciphertext) continue;
    try {
      nextIcons[record.id] = await decryptString(await getRecordKey(record.id), record.icon_ciphertext);
    } catch (error) {
      console.error(error);
    }
  }
  if (!vaultUnlocked.value) return;
  names.value = next;
  icons.value = nextIcons;
}

async function onUnlocked(): Promise<void> {
  if (!data.value) return;
  const edges = await buildRelationshipEdges(data.value.field_values, getRecordKey);
  if (!vaultUnlocked.value) return;
  relationshipEdges.value = edges;
  await decryptNames();
}

onVaultLockChange({
  unlocked: vaultUnlocked,
  run: () => void onUnlocked(),
  cleanup: () => { names.value = {}; icons.value = {}; relationshipEdges.value = []; clearFocus(); },
});

/**
 * The focus lives in the URL hash (`#focus=<id>`, written with replaceState so Inertia's history state is kept
 * and no entries pile up). Leaving for another page and coming back with the browser's back button reopens it.
 */
watch(trail, (ids) => {
  const hash = ids.length ? `#focus=${ids.join(',')}` : '';
  if (window.location.hash !== hash) window.history.replaceState(window.history.state, '', `${window.location.pathname}${window.location.search}${hash}`);
});

function restoreFocusFromUrl(): void {
  const ids = (new URLSearchParams(window.location.hash.slice(1)).get('focus') ?? '').split(',').filter((id) => nodeById.value.has(id));
  ids.forEach((id) => focusOn(id, false));
}

// New edges change the layout; refit unless the owner has already moved the view.
// In focus mode the ring changes too, so refit then as well.
watch(allEdges, () => { if (!userMoved && loaded.value) fitView(false); else scheduleDraw(); });
watch([names, resolvedTheme, hover], () => scheduleDraw());

let resizeObserver: ResizeObserver | null = null;

function measure(): void {
  const rect = container.value?.getBoundingClientRect();
  if (!rect) return;
  size = { width: Math.max(200, rect.width), height: Math.max(200, rect.height) };
  scheduleDraw();
}

onMounted(async () => {
  measure();
  resizeObserver = new ResizeObserver(measure);
  if (container.value) resizeObserver.observe(container.value);

  try {
    const { data: response } = await axios.get<GraphResponse>('/dashboard/connections/graph');
    data.value = response;
    serverEdges.value = response.edges;
    loaded.value = true;
    fitView(false);
    restoreFocusFromUrl();
    void onUnlocked();
  } catch (error) {
    console.error(error);
    loadFailed.value = true;
  }
});

onUnmounted(() => {
  resizeObserver?.disconnect();
  cancelAnimationFrame(animationFrame);
});

const focusIsSource = computed(() => !!focusId.value && nodeById.value.get(focusId.value)?.type === 'source');
/** The focused record on the Connections page: a source opens on its Sources tab. */
const connectionLink = computed(() => {
  if (!focusId.value) return '#';
  const state = { tab: focusIsSource.value ? ('sources' as const) : ('connections' as const), id: focusId.value };

  return `/dashboard/connections${buildConnectionsSearch(state, '')}${buildConnectionsHash(state)}`;
});
const trailName = (id: string) => nameOf(id) || (nodeById.value.get(id)?.type === 'source' ? 'Source' : 'Connection');
const focusDegree = computed(() => (focusId.value ? active.value.edges.filter((e) => e.from === focusId.value || e.to === focusId.value).length : 0));
</script>

<template>
  <p v-if="loadFailed" class="text-danger mb-0 small">Failed to load the connections map.</p>
  <div v-else ref="container" class="wtf-map" tabindex="0" @keydown="onKey">
    <canvas
      ref="canvas"
      class="wtf-map-canvas"
      :class="{ 'is-pointer': !!hover }"
      role="img"
      aria-label="Map of your connections. Scroll to zoom, drag to pan, click a connection to focus it."
      @pointerdown="onPointerDown"
      @pointermove="onPointerMove"
      @pointerup="onPointerUp"
      @pointercancel="onPointerUp"
      @pointerleave="onLeave"
      @wheel.prevent="onWheel"
    ></canvas>

    <div
      v-for="badge in badges"
      :key="badge.id"
      class="wtf-map-badge"
      :class="{ 'is-focus': trail.includes(badge.id), 'is-hover': badge.id === hover?.id }"
      :style="{ left: `${badge.x}px`, top: `${badge.y}px`, width: `${badge.diameter}px`, height: `${badge.diameter}px`, fontSize: `${badge.diameter * 0.62}px` }"
    >
      <SourceBadge :icon-key="badge.icon" :color-key="badge.color_key" size="100%" />
    </div>

    <div v-if="focusId" class="wtf-map-focus card shadow-sm">
      <div class="card-body py-2 px-3 d-flex align-items-center gap-3">
        <div class="min-w-0">
          <nav v-if="trail.length > 1" aria-label="Focus trail" class="small mb-1">
            <ol class="breadcrumb mb-0">
              <li v-for="(id, i) in trail" :key="id" class="breadcrumb-item" :class="{ active: i === trail.length - 1 }" :aria-current="i === trail.length - 1 ? 'page' : undefined">
                <span v-if="i === trail.length - 1">{{ trailName(id) }}</span>
                <button v-else type="button" class="btn btn-link btn-sm p-0 align-baseline" @click="focusOn(id)">{{ trailName(id) }}</button>
              </li>
            </ol>
          </nav>
          <div class="small text-muted">Focused on</div>
          <div class="fw-semibold text-truncate">{{ focusName || (focusIsSource ? 'Source (vault locked)' : 'Connection (vault locked)') }}</div>
          <div class="small text-muted">{{ focusDegree }} direct {{ focusDegree === 1 ? 'link' : 'links' }}</div>
        </div>
        <Link class="btn btn-sm btn-outline-secondary" :href="connectionLink">Open</Link>
        <button type="button" class="btn btn-sm btn-outline-secondary" @click="clearFocus">
          <AppIcon :icon="XIcon" /> Clear focus
        </button>
      </div>
    </div>

    <div class="wtf-map-zoom btn-group-vertical shadow-sm" role="group" aria-label="Zoom controls">
      <button type="button" class="btn btn-sm btn-secondary" aria-label="Zoom in" title="Zoom in (+)" @click="zoomBy(1.4)"><AppIcon :icon="PlusIcon" /></button>
      <button type="button" class="btn btn-sm btn-secondary" aria-label="Zoom out" title="Zoom out (-)" @click="zoomBy(1 / 1.4)"><AppIcon :icon="MinusIcon" /></button>
      <button type="button" class="btn btn-sm btn-secondary" aria-label="Fit to screen" title="Fit (0)" @click="fitView()"><AppIcon :icon="ScanIcon" /></button>
    </div>

    <div v-if="tooltip" class="wtf-map-tooltip card shadow-sm" :style="{ left: `${tooltip.x + 14}px`, top: `${tooltip.y + 14}px` }" role="tooltip">
      <div class="card-body py-2 px-3 d-flex gap-2 align-items-start">
        <img v-if="tooltip.picture" :src="tooltip.picture" alt="" class="wtf-map-tooltip-avatar" referrerpolicy="no-referrer">
        <SourceBadge v-else-if="tooltip.sourceIcon" :icon-key="tooltip.sourceIcon" :color-key="tooltip.colorKey" size="2.5rem" />
        <div>
          <div class="fw-semibold">{{ tooltip.title }}</div>
          <div class="small text-muted">{{ tooltip.kind }} · {{ tooltip.links }} {{ tooltip.links === 1 ? 'link' : 'links' }}</div>
          <div v-if="tooltip.via.length" class="small">Met via {{ tooltip.via.join(', ') }}</div>
          <div class="small text-muted">Click to focus</div>
        </div>
      </div>
    </div>

    <p v-if="loaded && !vaultUnlocked" class="wtf-map-hint small text-muted mb-0">
      Vault locked: names and relationship links appear after you unlock it.
    </p>
  </div>
</template>

<style scoped>
.wtf-map { position: relative; height: 70vh; min-height: 360px; overflow: hidden; border-radius: var(--bs-border-radius); outline-offset: 2px; }
.wtf-map-canvas { display: block; width: 100%; height: 100%; touch-action: none; cursor: grab; }
.wtf-map-canvas.is-pointer { cursor: pointer; }
.wtf-map-zoom { position: absolute; right: 0.75rem; top: 0.75rem; }
.wtf-map-focus { position: absolute; left: 0.75rem; top: 0.75rem; max-width: calc(100% - 5rem); }
.wtf-map-badge { display: flex; position: absolute; transform: translate(-50%, -50%); pointer-events: none; border-radius: 50%; box-shadow: 0 0 0 1.5px var(--bs-body-bg); }
.wtf-map-badge.is-focus, .wtf-map-badge.is-hover { box-shadow: 0 0 0 3px var(--bs-emphasis-color); }
.wtf-map-tooltip { position: absolute; pointer-events: none; max-width: 18rem; z-index: 2; }
.wtf-map-tooltip-avatar { width: 2.5rem; height: 2.5rem; border-radius: 50%; object-fit: cover; flex-shrink: 0; }
.wtf-map-hint { position: absolute; left: 0.75rem; bottom: 0.5rem; }
.min-w-0 { min-width: 0; }
</style>
