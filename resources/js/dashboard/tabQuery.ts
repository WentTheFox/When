/**
 * The active tab of a tabbed page lives in the `?tab=` query parameter (the
 * server can then link straight to a tab, e.g. the Discord OAuth callback
 * redirects to `/settings?tab=discord`). The URL hash is left for what a hash is
 * for: an element id to scroll to, or a record id.
 */

/** The `tab` query value, or null. `search` is `location.search`, with or without the `?`. */
export function readTabParam(search: string): string | null {
  return new URLSearchParams(search).get('tab');
}

/**
 * `search` with `tab` set to the given name, or removed when null (the default
 * tab is omitted). Other parameters are kept. Returns '' or a string starting
 * with `?`.
 */
export function withTabParam(search: string, tab: string | null): string {
  const params = new URLSearchParams(search);

  if (tab === null) params.delete('tab');
  else params.set('tab', tab);

  const query = params.toString();

  return query ? `?${query}` : '';
}
