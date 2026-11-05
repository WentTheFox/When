/**
 * Ordering helpers for the Connections page's master lists.
 *
 * Names are E2EE, so the server can't sort them: it stores an integer position
 * per record and serves the lists in that order. The client keeps each list in
 * that order as it renders — a record's name finishing its decryption must not
 * re-sort anything — and only moves a record when it is created or renamed
 * (and once, after the first unlock, to bring older data into alphabetical
 * order). These helpers decide where things go; saving is up to the caller.
 */

export type LabelOf<T> = (item: T) => string | undefined;

/** Case-aware alphabetical order; records whose name isn't known (failed to decrypt) go last, keeping their relative order. */
export function alphabetical<T>(items: readonly T[], labelOf: LabelOf<T>): T[] {
  return [...items].sort((a, b) => {
    const left = labelOf(a);
    const right = labelOf(b);

    if (left === undefined && right === undefined) return 0;
    if (left === undefined) return 1;
    if (right === undefined) return -1;

    return left.localeCompare(right);
  });
}

/**
 * Moves one record to its alphabetical place among the others, leaving every
 * other record exactly where it is. If the record's own name isn't known the
 * list is returned unchanged (it can't be placed yet).
 */
export function placeAlphabetically<T extends { id: string }>(items: readonly T[], id: string, labelOf: LabelOf<T>): T[] {
  const moving = items.find((item) => item.id === id);
  const label = moving ? labelOf(moving) : undefined;

  if (!moving || label === undefined) return [...items];

  const rest = items.filter((item) => item.id !== id);
  const at = rest.findIndex((item) => {
    const other = labelOf(item);

    return other !== undefined && other.localeCompare(label) > 0;
  });

  return at === -1 ? [...rest, moving] : [...rest.slice(0, at), moving, ...rest.slice(at)];
}

export function sameOrder(a: readonly { id: string }[], b: readonly { id: string }[]): boolean {
  return a.length === b.length && a.every((item, index) => item.id === b[index].id);
}
