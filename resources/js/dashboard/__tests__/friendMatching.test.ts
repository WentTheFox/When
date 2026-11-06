import { describe, expect, it } from 'vitest';
import { defaultFriendChoice, holdsOtherId, matchFriend, normalizeName, possibleMatches, type MatchCandidate } from '../friendMatching';

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

describe('possibleMatches', () => {
  const husky: MatchCandidate = { id: 'h', name: 'Someone', tokens: ['AerieHusky'] };

  it('finds a renamed account through a shared word in an old name', () => {
    const [hit] = possibleMatches('AerieBerry', [husky, bob]);
    expect(hit).toMatchObject({ id: 'h', via: 'AerieHusky', reason: 'word', detail: 'aerie' });
  });

  it('never offers an exact match (that is matchFriend\'s job) or an unrelated connection', () => {
    expect(possibleMatches('Alice Example', [alice])).toEqual([]);
    expect(possibleMatches('Zephyr', [alice, bob, husky])).toEqual([]);
  });

  it('ignores short words and empty names', () => {
    expect(possibleMatches('Kit Fox', [{ id: 'x', name: 'Kit Cat', tokens: [] }])).toEqual([]);
    expect(possibleMatches('', [alice])).toEqual([]);
    expect(possibleMatches(null, [alice])).toEqual([]);
  });

  it('catches a shared prefix and near-identical spellings', () => {
    expect(possibleMatches('Wolfgang99', [{ id: 'w', name: 'Wolfgang', tokens: [] }])[0]).toMatchObject({ id: 'w', reason: 'prefix' });
    expect(possibleMatches('Starlowe', [{ id: 's', name: 'Starlowee', tokens: [] }])[0]?.id).toBe('s');
  });

  it('ranks by strength, keeps one hit per connection and caps the list', () => {
    const many: MatchCandidate[] = ['One', 'Two', 'Three', 'Four', 'Five'].map((n, i) => ({ id: String(i), name: n, tokens: ['AerieHusky', 'AerieHusky2'] }));
    const hits = possibleMatches('AerieBerry', many);
    expect(hits).toHaveLength(3);
    expect(new Set(hits.map((h) => h.id)).size).toBe(3);
  });

  it('copes with names in other scripts', () => {
    expect(possibleMatches('信行 - Nobu', [{ id: 'n', name: 'Nobuyuki', tokens: [] }])).toEqual([]);
    expect(possibleMatches('Kɪʀᴏ Fox', [{ id: 'k', name: 'Kɪʀᴏ Wolf', tokens: [] }])[0]?.reason).toBe('word');
  });
});

describe('defaultFriendChoice', () => {
  it('preselects only a single exact match; everything else starts on a new connection', () => {
    expect(defaultFriendChoice(['c1'])).toBe('c1');
    expect(defaultFriendChoice([])).toBe('new');
    expect(defaultFriendChoice(['c1', 'c2'])).toBe('new');
  });

  it('never makes a lower-confidence guess the default', () => {
    const [guess] = possibleMatches('AerieBerry', [{ id: 'h', name: 'Someone', tokens: ['AerieHusky'] }]);
    expect(guess.id).toBe('h');
    // possible matches are not exact matches, so they are not passed in: the default stays "new".
    expect(defaultFriendChoice([])).toBe('new');
  });
});
