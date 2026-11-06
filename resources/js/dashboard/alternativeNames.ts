/**
 * A connection's other names (previous names, nicknames), kept in a custom field the owner picks in
 * Settings. The field is an identifier list (an array) or a multi-line field (one name per line), and
 * the connections search matches on it as well as on the name.
 */

/** The names in a field value: an array as stored, or text with one name per line. Trimmed, blanks and repeats dropped. */
export function parseAlternativeNames(raw: readonly string[] | string | null | undefined): string[] {
  const parts = typeof raw === 'string' ? raw.split(/\r?\n/) : [...(raw ?? [])];
  const seen = new Set<string>();

  for (const part of parts) {
    const name = part.trim();
    if (name !== '') seen.add(name);
  }

  return [...seen];
}

/**
 * Does this connection match a search? `query` is already trimmed and lowercased; an empty one matches all.
 * `via` is the other name that matched, set only when the real name did not, so the list can say why the
 * connection is there.
 */
export function matchConnection(name: string, alternatives: readonly string[], query: string): { matches: boolean; via: string | null } {
  if (query === '' || name.toLowerCase().includes(query)) return { matches: true, via: null };

  const via = alternatives.find((alternative) => alternative.toLowerCase().includes(query)) ?? null;

  return { matches: via !== null, via };
}
