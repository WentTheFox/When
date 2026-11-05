import { describe, expect, it } from 'vitest';
import { alphabetical, placeAlphabetically, sameOrder } from '../listOrder';

type Row = { id: string; label?: string };
const labelOf = (row: Row) => row.label;
const row = (id: string, label?: string): Row => ({ id, label });
const ids = (rows: Row[]) => rows.map((r) => r.id);

describe('alphabetical', () => {
  it('sorts by label', () => {
    expect(ids(alphabetical([row('c', 'Carol'), row('a', 'alice'), row('b', 'Bob')], labelOf))).toEqual(['a', 'b', 'c']);
  });

  it('puts records with an unknown name last, keeping their relative order', () => {
    expect(ids(alphabetical([row('x'), row('b', 'Bob'), row('y'), row('a', 'Alice')], labelOf))).toEqual(['a', 'b', 'x', 'y']);
  });

  it('does not mutate its input', () => {
    const input = [row('b', 'Bob'), row('a', 'Alice')];
    alphabetical(input, labelOf);
    expect(ids(input)).toEqual(['b', 'a']);
  });
});

describe('placeAlphabetically', () => {
  const sorted = [row('a', 'Alice'), row('c', 'Carol'), row('e', 'Eve')];

  it('inserts a new record between its neighbours without touching the others', () => {
    const result = placeAlphabetically([...sorted, row('d', 'Dave')], 'd', labelOf);
    expect(ids(result)).toEqual(['a', 'c', 'd', 'e']);
  });

  it('puts a record that sorts first at the start and last at the end', () => {
    expect(ids(placeAlphabetically([...sorted, row('0', 'Aaron')], '0', labelOf))).toEqual(['0', 'a', 'c', 'e']);
    expect(ids(placeAlphabetically([...sorted, row('z', 'Zed')], 'z', labelOf))).toEqual(['a', 'c', 'e', 'z']);
  });

  it('moves a renamed record to its new place', () => {
    const renamed = [row('a', 'Zoe'), row('c', 'Carol'), row('e', 'Eve')];
    expect(ids(placeAlphabetically(renamed, 'a', labelOf))).toEqual(['c', 'e', 'a']);
  });

  it('leaves an already well-placed record where it is', () => {
    expect(ids(placeAlphabetically(sorted, 'c', labelOf))).toEqual(['a', 'c', 'e']);
  });

  it('puts a record after existing ones with the same name', () => {
    const tied = [row('a', 'Sam'), row('b', 'Sam')];
    expect(ids(placeAlphabetically([...tied, row('c', 'Sam')], 'c', labelOf))).toEqual(['a', 'b', 'c']);
  });

  it('ignores records whose name is not known yet when choosing the spot, and does not move them', () => {
    const list = [row('a', 'Alice'), row('?'), row('e', 'Eve'), row('d', 'Dave')];
    expect(ids(placeAlphabetically(list, 'd', labelOf))).toEqual(['a', '?', 'd', 'e']);
  });

  it('returns the list unchanged if the moving record has no name yet, or is not in the list', () => {
    const list = [row('a', 'Alice'), row('x')];
    expect(ids(placeAlphabetically(list, 'x', labelOf))).toEqual(['a', 'x']);
    expect(ids(placeAlphabetically(list, 'missing', labelOf))).toEqual(['a', 'x']);
  });
});

describe('sameOrder', () => {
  it('compares ids in order', () => {
    expect(sameOrder([row('a'), row('b')], [row('a'), row('b')])).toBe(true);
    expect(sameOrder([row('a'), row('b')], [row('b'), row('a')])).toBe(false);
    expect(sameOrder([row('a')], [row('a'), row('b')])).toBe(false);
    expect(sameOrder([], [])).toBe(true);
  });
});
