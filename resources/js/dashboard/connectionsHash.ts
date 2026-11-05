/**
 * URL-hash state for the Connections page, so a reload (or a shared link)
 * reopens the same view:
 *
 *   #tab=sources&id=<uuid>
 *
 * `tab` is one of the page's tabs (Connections is the default and is omitted)
 * and `id` is the record open in that tab, if any. Only the active tab's
 * record is kept in the URL. The older `#connection-<uuid>` form is still
 * understood, since links to it exist.
 *
 * Ids are plain UUIDs and carry no secrets; names stay E2EE.
 */

export const PAGE_TABS = {
  connections: 'connections-tab-connections',
  sources: 'connections-tab-sources',
  categories: 'connections-tab-categories',
  attributes: 'connections-tab-attributes',
} as const;

export type PageTabName = keyof typeof PAGE_TABS;

export interface ConnectionsHashState {
  tab: PageTabName;
  /** The record open in `tab`. */
  id?: string;
}

const DEFAULT_TAB: PageTabName = 'connections';
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

export function isPageTab(value: string | null | undefined): value is PageTabName {
  return !!value && Object.prototype.hasOwnProperty.call(PAGE_TABS, value);
}

export function parseConnectionsHash(hash: string): ConnectionsHashState {
  const raw = hash.startsWith('#') ? hash.slice(1) : hash;

  const legacy = /^connection-(.+)$/.exec(raw);
  if (legacy) {
    return UUID.test(legacy[1]) ? { tab: 'connections', id: legacy[1].toLowerCase() } : { tab: DEFAULT_TAB };
  }

  const params = new URLSearchParams(raw);
  const tab = params.get('tab');
  const id = params.get('id');

  return {
    tab: isPageTab(tab) ? tab : DEFAULT_TAB,
    ...(id && UUID.test(id) ? { id: id.toLowerCase() } : {}),
  };
}

/** Inverse of parse; omits the default tab and returns '' when there is nothing to say. */
export function buildConnectionsHash(state: ConnectionsHashState): string {
  const params = new URLSearchParams();

  if (state.tab !== DEFAULT_TAB) params.set('tab', state.tab);
  if (state.id) params.set('id', state.id);

  const query = params.toString();

  return query ? `#${query}` : '';
}

/** True when the hash names a record (a bare tab does not count). */
export function hasRecord(state: ConnectionsHashState): boolean {
  return !!state.id;
}
