import { describe, expect, it } from 'vitest';
import { extractRelationships, IMPORT_MAX } from '../discordRelationshipsImport';

const HASH = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';
const row = (id: string, type: number, user: object = {}, extra: object = {}) => ({
  id, type, nickname: null, user: { id, username: 'someone', global_name: 'Some One', avatar: HASH, ...user }, ...extra,
});

describe('extractRelationships', () => {
  it('keeps friends and blocked users with the few fields we store, and skips everything else', () => {
    const text = JSON.stringify([
      row('100000000000000001', 1, {}, { nickname: 'Pal', token: 'secret' }),
      row('100000000000000002', 2),
      row('100000000000000003', 3),
      row('100000000000000004', 4),
    ]);
    const result = extractRelationships(text);

    expect(result.friends).toBe(1);
    expect(result.blocked).toBe(1);
    expect(result.entries).toEqual([
      { id: '100000000000000001', kind: 'friend', username: 'someone', global_name: 'Some One', nickname: 'Pal', avatar: HASH },
      { id: '100000000000000002', kind: 'blocked', username: 'someone', global_name: 'Some One', nickname: null, avatar: HASH },
    ]);
  });

  it('drops a field that is not valid, but keeps the person', () => {
    const long = 'x'.repeat(33);
    const [entry] = extractRelationships(JSON.stringify([
      row('100000000000000001', 1, { username: 'a', global_name: long, avatar: 'https://evil.example/x.png' }, { nickname: 'bad\u0007name' }),
    ])).entries;

    expect(entry).toEqual({ id: '100000000000000001', kind: 'friend', username: null, global_name: null, nickname: null, avatar: null });
  });

  it('accepts an animated avatar hash and a 32 character name counted in characters', () => {
    const [entry] = extractRelationships(JSON.stringify([
      row('100000000000000001', 2, { avatar: `a_${HASH}`, global_name: '🦊'.repeat(32) }),
    ])).entries;

    expect(entry.avatar).toBe(`a_${HASH}`);
    expect(entry.global_name).toBe('🦊'.repeat(32));
  });

  it('does not keep your nickname for a blocked person', () => {
    const [entry] = extractRelationships(JSON.stringify([row('100000000000000001', 2, {}, { nickname: 'Pal' })])).entries;
    expect(entry.nickname).toBeNull();
  });

  it('skips entries without a valid id, and duplicates', () => {
    const result = extractRelationships(JSON.stringify([
      row('not-an-id', 2),
      { type: 2, id: 12345, user: { id: ['1'] } },
      row('100000000000000002', 2),
      row('100000000000000002', 1),
      null,
      'text',
    ]));

    expect(result.entries.map((e) => e.id)).toEqual(['100000000000000002']);
  });

  it('falls back to the relationship id when the user object is missing', () => {
    expect(extractRelationships(JSON.stringify([{ id: '100000000000000009', type: 2 }])).entries[0]).toMatchObject({ id: '100000000000000009', username: null });
  });

  it('caps the import and says so', () => {
    const rows = Array.from({ length: IMPORT_MAX + 5 }, (_, i) => row(String(100000000000000000n + BigInt(i)), 2));
    const result = extractRelationships(JSON.stringify(rows));

    expect(result.entries).toHaveLength(IMPORT_MAX);
    expect(result.truncated).toBe(true);
  });

  it('explains what is wrong with text that is not a relationships list', () => {
    expect(() => extractRelationships('nope')).toThrow('not valid JSON');
    expect(() => extractRelationships('{"a":1}')).toThrow('Expected a list');
  });
});
