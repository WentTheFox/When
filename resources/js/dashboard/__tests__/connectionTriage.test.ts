import { describe, expect, it } from 'vitest';
import { floatUnsorted, needsSortingOut, type TriageConnection } from '../connectionTriage';

const TYPES: Record<string, string> = { id1: 'identifier', idl: 'identifier_list', txt: 'text', bool: 'boolean', rel: 'relationship' };
const typeOf = (definitionId: string) => TYPES[definitionId];

const conn = (id: string, values: TriageConnection['attribute_values'], over: Partial<TriageConnection> = {}): TriageConnection => ({
  id,
  archived: false,
  created_at: '2026-11-01T00:00:00+00:00',
  attribute_values: values,
  ...over,
});

const identifier = (def = 'id1', value = '123') => ({ attribute_definition_id: def, value_ciphertext: null, identifier_value: value });
const list = (entries: string[], def = 'idl') => ({ attribute_definition_id: def, value_ciphertext: null, identifier_list_value: entries });
const text = (def = 'txt') => ({ attribute_definition_id: def, value_ciphertext: 'opaque' });

describe('needsSortingOut', () => {
  it('is true for a connection whose only values are identifier or identifier list fields', () => {
    expect(needsSortingOut(conn('a', [identifier()]), typeOf)).toBe(true);
    expect(needsSortingOut(conn('a', [list(['1', '2'])]), typeOf)).toBe(true);
    expect(needsSortingOut(conn('a', [identifier(), list(['1'])]), typeOf)).toBe(true);
  });

  it('is false as soon as any other kind of field has a value: it has been sorted out', () => {
    expect(needsSortingOut(conn('a', [identifier(), text()]), typeOf)).toBe(false);
    expect(needsSortingOut(conn('a', [identifier(), { attribute_definition_id: 'bool', value_ciphertext: null, boolean_value: false }]), typeOf)).toBe(false);
    expect(needsSortingOut(conn('a', [identifier(), { attribute_definition_id: 'rel', value_ciphertext: 'opaque' }]), typeOf)).toBe(false);
  });

  it('is false for a connection with no identifier at all', () => {
    expect(needsSortingOut(conn('a', []), typeOf)).toBe(false);
    expect(needsSortingOut(conn('a', [text()]), typeOf)).toBe(false);
  });

  it('does not count a blank value as assigned, in either direction', () => {
    expect(needsSortingOut(conn('a', [identifier('id1', '  ')]), typeOf)).toBe(false);
    expect(needsSortingOut(conn('a', [list([])]), typeOf)).toBe(false);
    expect(needsSortingOut(conn('a', [identifier(), { attribute_definition_id: 'txt', value_ciphertext: '' }]), typeOf)).toBe(true);
    expect(needsSortingOut(conn('a', [identifier(), list([' '])]), typeOf)).toBe(true);
  });

  it('is false for an archived connection: it was set aside on purpose', () => {
    expect(needsSortingOut(conn('a', [identifier()], { archived: true }), typeOf)).toBe(false);
  });

  it('is false when a value belongs to a field it does not know the type of', () => {
    expect(needsSortingOut(conn('a', [identifier(), text('gone')]), typeOf)).toBe(false);
  });
});

describe('floatUnsorted', () => {
  const at = (day: number) => `2026-11-${String(day).padStart(2, '0')}T12:00:00+00:00`;

  it('puts connections that still need sorting first, newest created first, and leaves the rest in their order', () => {
    const list_ = [
      conn('alice', [text()]),
      conn('old', [identifier()], { created_at: at(2) }),
      conn('bob', [text()]),
      conn('new', [identifier()], { created_at: at(9) }),
      conn('mid', [list(['1'])], { created_at: at(5) }),
      conn('carol', []),
    ];

    expect(floatUnsorted(list_, typeOf).map((c) => c.id)).toEqual(['new', 'mid', 'old', 'alice', 'bob', 'carol']);
  });

  it('keeps the order untouched when nothing needs sorting, and does not change the input', () => {
    const list_ = [conn('b', [text()]), conn('a', [text()])];

    expect(floatUnsorted(list_, typeOf).map((c) => c.id)).toEqual(['b', 'a']);
    expect(list_.map((c) => c.id)).toEqual(['b', 'a']);
  });

  it('is stable for connections created at the same moment, and puts one with no date last among them', () => {
    const list_ = [
      conn('x', [identifier()], { created_at: null }),
      conn('p', [identifier()], { created_at: at(3) }),
      conn('q', [identifier()], { created_at: at(3) }),
    ];

    expect(floatUnsorted(list_, typeOf).map((c) => c.id)).toEqual(['p', 'q', 'x']);
  });
});
