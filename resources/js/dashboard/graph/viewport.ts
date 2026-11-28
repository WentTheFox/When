import type { Point } from './types';

export interface View { scale: number; tx: number; ty: number }
export interface Size { width: number; height: number }

export const MIN_SCALE = 0.2;
export const MAX_SCALE = 16;

const clamp = (v: number, lo: number, hi: number) => Math.min(hi, Math.max(lo, v));

export const worldToScreen = (view: View, p: Point): Point => ({ x: p.x * view.scale + view.tx, y: p.y * view.scale + view.ty });
export const screenToWorld = (view: View, p: Point): Point => ({ x: (p.x - view.tx) / view.scale, y: (p.y - view.ty) / view.scale });

/** Zoom by `factor`, keeping the world point under `cursor` fixed on screen. */
export function zoomAt(view: View, cursor: Point, factor: number): View {
  const scale = clamp(view.scale * factor, MIN_SCALE, MAX_SCALE);
  const real = scale / view.scale;

  return { scale, tx: cursor.x - (cursor.x - view.tx) * real, ty: cursor.y - (cursor.y - view.ty) * real };
}

/** A view that centres the points' bounding box in `size` with padding; scale is capped so a lone node isn't huge. */
export function fitTo(points: Point[], size: Size, padding = 40, maxScale = 4): View {
  if (points.length === 0) return { scale: 1, tx: size.width / 2, ty: size.height / 2 };

  const xs = points.map((p) => p.x); const ys = points.map((p) => p.y);
  const minX = Math.min(...xs); const maxX = Math.max(...xs);
  const minY = Math.min(...ys); const maxY = Math.max(...ys);
  const spanX = Math.max(maxX - minX, 1); const spanY = Math.max(maxY - minY, 1);
  const scale = clamp(Math.min((size.width - padding * 2) / spanX, (size.height - padding * 2) / spanY, maxScale), MIN_SCALE, MAX_SCALE);

  return { scale, tx: size.width / 2 - ((minX + maxX) / 2) * scale, ty: size.height / 2 - ((minY + maxY) / 2) * scale };
}
