/**
 * URL-hash state for the Connections page, so a reload (or a shared link)
 * reopens the same records:
 *
 *   #connection=<uuid>&tab=edit&source=<uuid>&category=<uuid>&attribute=<uuid>
 *
 * Every key is optional. `tab` only means anything together with
 * `connection` (it is the open connection card's tab). The older
 * `#connection-<uuid>` form is still understood, since links to it exist.
 *
 * Ids are plain UUIDs and carry no secrets; names stay E2EE.
 */

export const CONNECTION_TABS = {
  overview: 'connection-tab-overview',
  edit: 'connection-tab-edit',
  relationships: 'connection-tab-relationships',
  sharing: 'connection-tab-sharing',
} as const;

export type ConnectionTabName = keyof typeof CONNECTION_TABS;

export interface ConnectionsHashState {
  connection?: string;
  /** The connection card tab's id (CONNECTION_TABS value), not its short name. */
  tab?: string;
  source?: string;
  category?: string;
  attribute?: string;
}

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const RECORD_KEYS = ['connection', 'source', 'category', 'attribute'] as const;

export function parseConnectionsHash(hash: string): ConnectionsHashState {
  const raw = hash.startsWith('#') ? hash.slice(1) : hash;
  const state: ConnectionsHashState = {};

  const legacy = /^connection-(.+)$/.exec(raw);
  if (legacy) {
    if (UUID.test(legacy[1])) state.connection = legacy[1].toLowerCase();
    return state;
  }

  const params = new URLSearchParams(raw);

  for (const key of RECORD_KEYS) {
    const value = params.get(key);
    if (value && UUID.test(value)) state[key] = value.toLowerCase();
  }

  const tab = params.get('tab');
  if (tab && tab in CONNECTION_TABS && state.connection) {
    state.tab = CONNECTION_TABS[tab as ConnectionTabName];
  }

  return state;
}

/** Inverse of parse; omits defaults (the overview tab, a tab with no connection) and returns '' for an empty state. */
export function buildConnectionsHash(state: ConnectionsHashState): string {
  const params = new URLSearchParams();

  for (const key of RECORD_KEYS) {
    const value = state[key];
    if (value) params.set(key, value);
  }

  if (state.connection && state.tab && state.tab !== CONNECTION_TABS.overview) {
    const name = (Object.keys(CONNECTION_TABS) as ConnectionTabName[]).find((n) => CONNECTION_TABS[n] === state.tab);
    if (name) params.set('tab', name);
  }

  const query = params.toString();

  return query ? `#${query}` : '';
}

/** True when the hash names at least one record (a bare tab does not count). */
export function hasRecord(state: ConnectionsHashState): boolean {
  return RECORD_KEYS.some((key) => !!state[key]);
}
