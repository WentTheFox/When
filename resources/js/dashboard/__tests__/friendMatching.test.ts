import { describe, expect, it } from 'vitest';
import { coreName, defaultFriendChoice, holdsOtherId, matchFriend, matchFriendByNames, normalizeName, possibleMatches, possibleMatchesByNames, type MatchCandidate } from '../friendMatching';

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

describe('coreName', () => {
  it('keeps only Latin letters and digits, ignoring case', () => {
    expect(coreName('Maple Sparrow星星')).toBe('maplesparrow');
    expect(coreName('maple_sparrow ✨')).toBe('maplesparrow');
    expect(coreName('Wolfgang99')).toBe('wolfgang99');
  });

  it('is empty for a name wholly in another script', () => {
    expect(coreName('信行')).toBe('');
  });
});

describe('matchFriend, same name under different decoration', () => {
  const maple: MatchCandidate = { id: 'c', name: 'Maple Sparrow', tokens: [] };

  it.each(['Maple Sparrow星星', 'Maple Sparrow 星星', 'MapleSparrow星星', 'Maple Sparrow✨', 'maple_sparrow (星星)', '星星Maple Sparrow'])(
    'matches %s',
    (name) => {
      expect(matchFriend(name, [maple, bob])).toEqual({ kind: 'one', connectionId: 'c', by: 'core' });
    },
  );

  it('also matches through a highlight token', () => {
    expect(matchFriend('Sparrow星星', [{ id: 't', name: 'Someone', tokens: ['Sparrow'] }])).toEqual({ kind: 'one', connectionId: 't', by: 'core' });
    expect(matchFriend('Maple_Sparrow星星', [{ id: 't', name: 'Someone', tokens: ['Maple Sparrow'] }])).toEqual({ kind: 'one', connectionId: 't', by: 'core' });
  });

  it('prefers an exact match over a decorated one', () => {
    const twin: MatchCandidate = { id: 'twin', name: 'Maple Sparrow星星', tokens: [] };
    expect(matchFriend('Maple Sparrow星星', [maple, twin])).toEqual({ kind: 'one', connectionId: 'twin', by: 'name' });
  });

  it('is ambiguous when several connections share the stripped name', () => {
    expect(matchFriend('Maple Sparrow星星', [maple, { id: 'c2', name: 'maple-sparrow', tokens: [] }]).kind).toBe('several');
  });

  it('keeps digits and extra words significant, and ignores cores that are too short', () => {
    expect(matchFriend('Wolfgang99', [{ id: 'w', name: 'Wolfgang', tokens: [] }])).toEqual({ kind: 'none' });
    expect(matchFriend('Alice', [alice])).toEqual({ kind: 'none' });
    expect(matchFriend('Al糖', [{ id: 'a', name: 'Al', tokens: [] }])).toEqual({ kind: 'none' });
  });

  it('does not treat a wholly non-Latin name as matching anything by core', () => {
    expect(matchFriend('信行', [{ id: 'n', name: '行信', tokens: [] }])).toEqual({ kind: 'none' });
  });
});

describe('possibleMatches, names with characters from another script', () => {
  const maple: MatchCandidate = { id: 'c', name: 'Maple Sparrow', tokens: [] };

  it('finds a connection when the other script is glued onto a word', () => {
    expect(possibleMatches('Sparrow星星', [maple])[0]).toMatchObject({ id: 'c', reason: 'word', detail: 'sparrow' });
  });

  it('reports the longest shared word, not the first', () => {
    // "sugar" and "sparrow" are both shared; the longer one is reported.
    expect(possibleMatches('Sugar Sparrow', [{ id: 'x', name: 'Sugar Sparrow Lane', tokens: [] }])[0]).toMatchObject({ reason: 'word', detail: 'sparrow' });
    expect(possibleMatches('Sugar Sparrow', [maple])[0]).toMatchObject({ reason: 'word', detail: 'sparrow' });
  });

  it('leaves what matchFriend already links to alone', () => {
    expect(possibleMatches('Maple Sparrow星星', [maple])).toEqual([]);
  });
});

describe('several names for one friend', () => {
  const maple: MatchCandidate = { id: 'c', name: 'Maple Sparrow', tokens: [] };

  it('lets the first name that matches decide', () => {
    expect(matchFriendByNames([null, 'Nobody', 'Maple Sparrow星星'], [maple])).toEqual({ kind: 'one', connectionId: 'c', by: 'core' });
    expect(matchFriendByNames(['Nobody', null], [maple])).toEqual({ kind: 'none' });
  });

  it('keeps the best guess per connection across names', () => {
    const hits = possibleMatchesByNames(['Sugar Sparrow', 'Sparrow星星'], [maple]);
    expect(hits).toHaveLength(1);
    expect(hits[0].id).toBe('c');
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
