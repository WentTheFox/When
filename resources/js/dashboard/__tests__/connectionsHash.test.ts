import { describe, expect, it } from 'vitest';
import {
  buildConnectionsHash,
  CONNECTION_TABS,
  hasRecord,
  parseConnectionsHash,
} from '../connectionsHash';

const A = '11111111-1111-4111-8111-111111111111';
const B = '22222222-2222-4222-8222-222222222222';

describe('parseConnectionsHash', () => {
  it('reads a connection and its tab', () => {
    expect(parseConnectionsHash(`#connection=${A}&tab=edit`)).toEqual({ connection: A, tab: CONNECTION_TABS.edit });
  });

  it('reads records in the other lists', () => {
    expect(parseConnectionsHash(`#source=${A}&category=${B}&attribute=${A}`)).toEqual({ source: A, category: B, attribute: A });
  });

  it('still understands the older #connection-<id> links', () => {
    expect(parseConnectionsHash(`#connection-${A}`)).toEqual({ connection: A });
  });

  it('ignores a tab with no connection, unknown tabs, and non-UUID ids', () => {
    expect(parseConnectionsHash('#tab=edit')).toEqual({});
    expect(parseConnectionsHash(`#connection=${A}&tab=bogus`)).toEqual({ connection: A });
    expect(parseConnectionsHash('#connection=not-a-uuid&source=<script>')).toEqual({});
    expect(parseConnectionsHash('#connection-not-a-uuid')).toEqual({});
  });

  it('handles empty and hash-less input', () => {
    expect(parseConnectionsHash('')).toEqual({});
    expect(parseConnectionsHash('#')).toEqual({});
    expect(parseConnectionsHash(`connection=${A}`)).toEqual({ connection: A });
  });

  it('lowercases ids so they match the server', () => {
    expect(parseConnectionsHash(`#connection=${A.toUpperCase()}`)).toEqual({ connection: A });
  });
});

describe('buildConnectionsHash', () => {
  it('omits the default tab and returns an empty string for an empty state', () => {
    expect(buildConnectionsHash({ connection: A, tab: CONNECTION_TABS.overview })).toBe(`#connection=${A}`);
    expect(buildConnectionsHash({})).toBe('');
  });

  it('writes a non-default tab by its short name', () => {
    expect(buildConnectionsHash({ connection: A, tab: CONNECTION_TABS.sharing })).toBe(`#connection=${A}&tab=sharing`);
  });

  it('drops a tab that has no connection', () => {
    expect(buildConnectionsHash({ tab: CONNECTION_TABS.edit })).toBe('');
  });

  it('round-trips every combination', () => {
    const states = [
      { connection: A, tab: CONNECTION_TABS.relationships },
      { source: A, category: B },
      { connection: A, source: B, category: A, attribute: B, tab: CONNECTION_TABS.edit },
    ];
    for (const state of states) {
      expect(parseConnectionsHash(buildConnectionsHash(state))).toEqual(state);
    }
  });
});

describe('hasRecord', () => {
  it('is true for any record id and false for a bare tab or nothing', () => {
    expect(hasRecord({ source: A })).toBe(true);
    expect(hasRecord({ tab: CONNECTION_TABS.edit })).toBe(false);
    expect(hasRecord({})).toBe(false);
  });
});
