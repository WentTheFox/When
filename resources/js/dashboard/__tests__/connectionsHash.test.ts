import { describe, expect, it } from 'vitest';
import { buildConnectionsHash, buildConnectionsSearch, hasRecord, isPageTab, parseConnectionsHash } from '../connectionsHash';

const A = '11111111-1111-4111-8111-111111111111';

describe('parseConnectionsHash', () => {
  it('reads a tab and the record open in it', () => {
    expect(parseConnectionsHash(`#tab=sources&id=${A}`)).toEqual({ tab: 'sources', id: A });
  });

  it('defaults to the connections tab', () => {
    expect(parseConnectionsHash(`#id=${A}`)).toEqual({ tab: 'connections', id: A });
    expect(parseConnectionsHash('')).toEqual({ tab: 'connections' });
    expect(parseConnectionsHash('#')).toEqual({ tab: 'connections' });
  });

  it('opens a tab without a record', () => {
    expect(parseConnectionsHash('#tab=attributes')).toEqual({ tab: 'attributes' });
  });

  it('still understands the older #connection-<id> links', () => {
    expect(parseConnectionsHash(`#connection-${A}`)).toEqual({ tab: 'connections', id: A });
  });

  it('ignores unknown tabs and non-UUID ids', () => {
    expect(parseConnectionsHash('#tab=bogus')).toEqual({ tab: 'connections' });
    expect(parseConnectionsHash('#tab=sources&id=<script>')).toEqual({ tab: 'sources' });
    expect(parseConnectionsHash('#connection-not-a-uuid')).toEqual({ tab: 'connections' });
    expect(parseConnectionsHash('#tab=constructor')).toEqual({ tab: 'connections' });
  });

  it('accepts a hash without the leading #, and lowercases ids so they match the server', () => {
    expect(parseConnectionsHash(`tab=categories&id=${A.toUpperCase()}`)).toEqual({ tab: 'categories', id: A });
  });
});

describe('query tab', () => {
  it('reads the tab from ?tab= and the record from the hash', () => {
    expect(parseConnectionsHash(`#id=${A}`, '?tab=sources')).toEqual({ tab: 'sources', id: A });
    expect(parseConnectionsHash('', '?tab=bogus')).toEqual({ tab: 'connections' });
  });
});

describe('buildConnectionsHash / buildConnectionsSearch', () => {
  it('omits the default tab and an empty record', () => {
    expect(buildConnectionsSearch({ tab: 'connections' })).toBe('');
    expect(buildConnectionsHash({ tab: 'connections' })).toBe('');
    expect(buildConnectionsHash({ tab: 'connections', id: A })).toBe(`#id=${A}`);
  });

  it('writes a non-default tab to the query and keeps other parameters', () => {
    expect(buildConnectionsSearch({ tab: 'sources' })).toBe('?tab=sources');
    expect(buildConnectionsSearch({ tab: 'sources' }, '?a=1')).toBe('?a=1&tab=sources');
  });

  it('round-trips every tab, with and without a record', () => {
    for (const tab of ['connections', 'sources', 'categories', 'attributes'] as const) {
      for (const state of [{ tab }, { tab, id: A }]) {
        expect(parseConnectionsHash(buildConnectionsHash(state), buildConnectionsSearch(state))).toEqual(state);
      }
    }
  });
});

describe('helpers', () => {
  it('hasRecord is true only when an id is named', () => {
    expect(hasRecord({ tab: 'sources', id: A })).toBe(true);
    expect(hasRecord({ tab: 'sources' })).toBe(false);
  });

  it('isPageTab accepts only real tab names', () => {
    expect(isPageTab('sources')).toBe(true);
    expect(isPageTab('bogus')).toBe(false);
    expect(isPageTab(null)).toBe(false);
    expect(isPageTab('toString')).toBe(false);
  });
});
