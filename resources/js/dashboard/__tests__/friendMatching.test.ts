import { describe, expect, it } from 'vitest';
import { holdsOtherId, matchFriend, needsListConversion, normalizeName, type MatchCandidate, type ReviewItem } from '../friendMatching';

const alice: MatchCandidate = { id: 'a', name: 'Alice Example', tokens: ['Ali', 'alice_vr'] };
const bob: MatchCandidate = { id: 'b', name: 'Bob', tokens: [] };

describe('normalizeName', () => {
  it('ignores case, surrounding and repeated space, and compatibility forms', () => {
    expect(normalizeName('  Alice   EXAMPLE ')).toBe('alice example');
    expect(normalizeName('Ａlice')).toBe('alice');
  });
});

describe('matchFriend', () => {
  it('matches on the connection name', () => {
    expect(matchFriend('alice example', [alice, bob])).toEqual({ kind: 'one', connectionId: 'a', by: 'name' });
  });

  it('matches on a highlight token', () => {
    expect(matchFriend('ALICE_VR', [alice, bob])).toEqual({ kind: 'one', connectionId: 'a', by: 'token' });
  });

  it('does not match partially: a wrong link is worse than a duplicate', () => {
    expect(matchFriend('Alice', [alice, bob])).toEqual({ kind: 'none' });
    expect(matchFriend('Bobby', [alice, bob])).toEqual({ kind: 'none' });
  });

  it('reports ambiguity when several connections match, by name or token', () => {
    const other: MatchCandidate = { id: 'c', name: 'Someone', tokens: ['Bob'] };
    expect(matchFriend('bob', [alice, bob, other])).toEqual({ kind: 'several', connectionIds: ['b', 'c'] });
  });

  it('counts one connection once even if both its name and a token match', () => {
    expect(matchFriend('Bob', [{ id: 'b', name: 'Bob', tokens: ['bob'] }])).toEqual({ kind: 'one', connectionId: 'b', by: 'name' });
  });

  it('never matches an empty name, or a connection whose name could not be decrypted', () => {
    expect(matchFriend('', [{ id: 'x', name: '', tokens: [''] }])).toEqual({ kind: 'none' });
    expect(matchFriend(null, [alice])).toEqual({ kind: 'none' });
    expect(matchFriend('Alice Example', [{ id: 'x', name: '', tokens: [] }])).toEqual({ kind: 'none' });
  });
});

describe('holdsOtherId', () => {
  it('is true only for a non-empty id that differs', () => {
    expect(holdsOtherId('usr_a', 'usr_b')).toBe(true);
    expect(holdsOtherId(' USR_A ', 'usr_a')).toBe(false);
    expect(holdsOtherId('', 'usr_a')).toBe(false);
    expect(holdsOtherId(null, 'usr_a')).toBe(false);
  });
});

describe('needsListConversion', () => {
  const item: ReviewItem = {
    friendId: 'f1', vrchatId: 'usr_x', name: 'X',
    candidates: [{ id: 'a', name: 'A', conflict: true }, { id: 'b', name: 'B', conflict: false }],
  };

  it('is needed only when a choice maps onto a connection that already holds another id', () => {
    expect(needsListConversion([item], { f1: 'a' })).toBe(true);
    expect(needsListConversion([item], { f1: 'b' })).toBe(false);
    expect(needsListConversion([item], { f1: 'new' })).toBe(false);
    expect(needsListConversion([item], { f1: 'skip' })).toBe(false);
    expect(needsListConversion([], {})).toBe(false);
  });
});
