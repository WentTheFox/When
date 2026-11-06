import { describe, expect, it } from 'vitest';
import { choiceOfBoolean, convertedText, featuresBrokenBy, hasWayBack, identifierEntriesOf, listTextOf, pathsFrom, rulesFor, type MigrationPath } from '../fieldMigrations';
import { listEntries } from '../encryptedList';

const paths: MigrationPath[] = [
  { from: 'text', to: 'textarea', tier: 'e2ee', lossy: false },
  { from: 'textarea', to: 'text', tier: 'e2ee', lossy: true },
  { from: 'text', to: 'identifier', tier: 'to_plain', lossy: false },
];

describe('fieldMigrations', () => {
  it('lists the paths that start at a type', () => {
    expect(pathsFrom(paths, 'text').map((p) => p.to)).toEqual(['textarea', 'identifier']);
    expect(pathsFrom(paths, 'date')).toEqual([]);
  });

  it('knows whether a change can be undone', () => {
    expect(hasWayBack(paths, paths[0]!)).toBe(true);
    expect(hasWayBack(paths, paths[2]!)).toBe(false);
  });

  it('finds the features a new type would break', () => {
    const types = { 'Highlight tokens': ['identifier_list'], 'Alternative names': ['identifier_list', 'list'] };
    expect(featuresBrokenBy(['Highlight tokens', 'Alternative names'], types, 'list')).toEqual(['Highlight tokens']);
    expect(featuresBrokenBy([], types, 'list')).toEqual([]);
  });

  it('converts text values per path', () => {
    expect(convertedText('textarea', 'text', 'a\n\n  b \nc')).toBe('a b c');
    expect(convertedText('text', 'email', 'x@y.z')).toBe('x@y.z');
    expect(listEntries(convertedText('textarea', 'list', 'a\nb\na'))).toEqual(['a', 'b']);
    expect(convertedText('list', 'textarea', 'a\nb\n\n\n')).toBe('a\nb');
    expect(convertedText('textarea', 'list', '  \n ')).toBe('');
  });

  it('round-trips an identifier list through a list value', () => {
    expect(identifierEntriesOf(listTextOf(['a', 'b']))).toEqual(['a', 'b']);
    expect(listTextOf([])).toBe('');
  });

  it('maps a yes/no value to a choice', () => {
    expect(choiceOfBoolean(true)).toBe('Yes');
    expect(choiceOfBoolean(false)).toBe('No');
    expect(choiceOfBoolean(null)).toBe('No');
  });

  it('drops icon rules that the new type cannot evaluate', () => {
    const rule = (op: 'regex' | 'nonEmpty') => ({ id: op, icon: 'star', color: null, condition: { op } as never });
    const set = { version: 1, stopAfterFirst: false, rules: [rule('regex'), rule('nonEmpty')] };
    expect(rulesFor(set, 'date').rules.map((r) => r.id)).toEqual(['nonEmpty']);
    expect(rulesFor(set, 'text').rules).toHaveLength(2);
  });
});
