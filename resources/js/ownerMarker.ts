/**
 * Remembers, in this browser only, an opaque marker identifying the owner
 * account(s) that have logged in here, so a later logged-out view of their
 * own share link isn't counted as a visit (see ShareLinkVisitController).
 * Sent as `owner_markers`; the server decides whether any match the link's
 * owner. Matching grants nothing but "not recorded" — the owner-only view
 * still requires a real login.
 */
const STORAGE_KEY = 'ownerMarkers';
const MAX_MARKERS = 5;

export function getOwnerMarkers(): string[] {
  try {
    const parsed = JSON.parse(localStorage.getItem(STORAGE_KEY) ?? '[]');
    return Array.isArray(parsed) ? parsed.filter((m): m is string => typeof m === 'string') : [];
  } catch {
    return [];
  }
}

export function rememberOwnerMarker(marker: string | null | undefined): void {
  if (!marker) return;
  try {
    const markers = getOwnerMarkers();
    if (markers.includes(marker)) return;
    localStorage.setItem(STORAGE_KEY, JSON.stringify([marker, ...markers].slice(0, MAX_MARKERS)));
  } catch {
    // Storage unavailable (private window, blocked) — visits just get recorded as before.
  }
}

export function clearOwnerMarkers(): void {
  try {
    localStorage.removeItem(STORAGE_KEY);
  } catch {
    // Nothing to clear if storage is unavailable.
  }
}
