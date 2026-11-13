/**
 * Matching a VRChat friend to a connection you already have, so that adding
 * existing friends doesn't duplicate people. It runs in the browser because
 * connection names are end-to-end encrypted.
 *
 * A friend matches a connection when their VRChat display name equals the
 * connection's name, or equals one of its highlight tokens, ignoring case,
 * surrounding space and repeated spaces. When nothing matches that way, a name
 * that is the same once decoration is stripped (see coreName: "Maple Sparrow" and
 * "Maple Sparrow星星") counts too. Anything looser is only ever a hint
 * (possibleMatches): a fuzzy link to the wrong person is worse than a duplicate.
 */

export interface MatchCandidate {
  id: string;
  /** The decrypted connection name ('' when it couldn't be decrypted). */
  name: string;
  /** The connection's highlight tokens (plaintext, §0.2 tier). */
  tokens: string[];
}

/** 'core' = the same name apart from decoration (symbols, emoji, characters from another script, spacing). */
export type MatchBy = 'name' | 'token' | 'core';

export type FriendMatch =
  | { kind: 'none' }
  | { kind: 'one'; connectionId: string; by: MatchBy }
  | { kind: 'several'; connectionIds: string[] };

export function normalizeName(value: string): string {
  return value.normalize('NFKC').trim().replace(/\s+/g, ' ').toLowerCase();
}

/** Shorter than this, a stripped name is too generic to identify anyone ("Al", "J"). */
const MIN_CORE = 4;

/**
 * A name reduced to its Latin letters and digits: no spaces, punctuation, emoji or
 * characters from other scripts. "Maple Sparrow星星", "maple_sparrow ✨" and "MapleSparrow"
 * all become "maplesparrow". Digits stay, so "Wolfgang99" is not "Wolfgang". '' when
 * there is nothing Latin to go on (a name wholly in another script).
 */
export function coreName(value: string): string {
  return normalizeName(value).replace(/[^\p{Script=Latin}\p{N}]+/gu, '');
}

/** Zero matches: create a connection. One: link to it. Several: ambiguous, leave it to the owner. */
export function matchFriend(displayName: string | null, candidates: readonly MatchCandidate[]): FriendMatch {
  const wanted = normalizeName(displayName ?? '');
  if (wanted === '') return { kind: 'none' };

  const hits: { id: string; by: MatchBy }[] = [];

  for (const candidate of candidates) {
    if (normalizeName(candidate.name) === wanted) {
      hits.push({ id: candidate.id, by: 'name' });
    } else if (candidate.tokens.some((token) => normalizeName(token) === wanted)) {
      hits.push({ id: candidate.id, by: 'token' });
    }
  }

  // Only when nothing matched exactly: the same name under different decoration.
  const core = coreName(wanted);
  if (hits.length === 0 && core.length >= MIN_CORE) {
    for (const candidate of candidates) {
      if ([candidate.name, ...candidate.tokens].some((value) => coreName(value) === core)) {
        hits.push({ id: candidate.id, by: 'core' });
      }
    }
  }

  if (hits.length === 0) return { kind: 'none' };
  if (hits.length === 1) return { kind: 'one', connectionId: hits[0].id, by: hits[0].by };

  return { kind: 'several', connectionIds: hits.map((h) => h.id) };
}

/**
 * True when a single-identifier field already holds a *different* VRChat id, so
 * adding this friend means the field has to become a list first.
 */
export function holdsOtherId(identifierValue: string | null | undefined, vrchatId: string): boolean {
  const current = (identifierValue ?? '').trim().toLowerCase();

  return current !== '' && current !== vrchatId.trim().toLowerCase();
}

/**
 * True when a batch of decisions puts more than one different id on the same existing connection. A
 * single-identifier field holds one id, so the field has to become a list first. holdsOtherId only compares an id
 * with what is already stored, so it cannot see this: two accounts of one person, both matched to the same
 * connection that has no id yet, each look fine alone and the second would fail once the first is saved.
 * Decisions for a new connection or a dismissal never share a field.
 */
export function batchNeedsList(decisions: readonly { choice: string; externalId: string }[]): boolean {
  const seen = new Map<string, Set<string>>();

  for (const { choice, externalId } of decisions) {
    if (choice === 'new' || choice === 'skip') continue;

    const ids = seen.get(choice) ?? new Set<string>();
    ids.add(externalId.trim().toLowerCase());
    seen.set(choice, ids);

    if (ids.size > 1) return true;
  }

  return false;
}

/**
 * What a pending friend starts on: the connection that matched exactly when there is
 * just one, otherwise a new connection. Lower-confidence guesses (possibleMatches)
 * are never the default, and neither is picking one of several matches.
 */
export function defaultFriendChoice(exactMatchIds: readonly string[]): 'new' | string {
  return exactMatchIds.length === 1 ? exactMatchIds[0] : 'new';
}

export interface PossibleMatch {
  /** The connection that might be the same person. */
  id: string;
  /** The name or highlight token that looked alike. */
  via: string;
  reason: 'word' | 'prefix' | 'similar';
  /** What they have in common, for the owner to judge ("aerie"). */
  detail: string;
  score: number;
}

/**
 * Words of a name, split on punctuation, spaces, camelCase humps and changes of script:
 * "AerieBerry" gives aerie, berry; "Sparrow星星" gives sparrow (and 星星, too short to count).
 * Short words are noise.
 */
function wordsOf(value: string): string[] {
  return (value
    .normalize('NFKC')
    .replace(/(\p{Ll})(\p{Lu})/gu, '$1 $2')
    .match(/[\p{Script=Latin}\p{N}]+|(?:(?![\p{Script=Latin}])\p{L})+/gu) ?? [])
    .map((w) => w.toLowerCase())
    .filter((w) => w.length >= 4);
}

function levenshtein(a: string, b: string): number {
  const row = Array.from({ length: b.length + 1 }, (_, j) => j);

  for (let i = 1; i <= a.length; i++) {
    let diagonal = row[0];
    row[0] = i;

    for (let j = 1; j <= b.length; j++) {
      const above = row[j];
      row[j] = Math.min(row[j] + 1, row[j - 1] + 1, diagonal + (a[i - 1] === b[j - 1] ? 0 : 1));
      diagonal = above;
    }
  }

  return row[b.length];
}

function compareNames(wanted: string, other: string): Omit<PossibleMatch, 'id' | 'via'> | null {
  const a = normalizeName(wanted).replace(/\s+/g, '');
  const b = normalizeName(other).replace(/\s+/g, '');
  if (a === '' || b === '' || a === b) return null;

  // The longest shared word is the most telling one ("sparrow" over "pop").
  const otherWords = wordsOf(other);
  const shared = wordsOf(wanted).filter((w) => otherWords.includes(w)).sort((x, y) => y.length - x.length)[0];
  if (shared) return { reason: 'word', detail: shared, score: 0.8 + Math.min(shared.length, 10) / 100 };

  let prefix = 0;
  while (prefix < a.length && prefix < b.length && a[prefix] === b[prefix]) prefix++;
  if (prefix >= 5 && prefix >= Math.min(a.length, b.length) * 0.6) return { reason: 'prefix', detail: a.slice(0, prefix), score: 0.7 + prefix / 100 };

  const similarity = 1 - levenshtein(a, b) / Math.max(a.length, b.length);
  if (Math.min(a.length, b.length) >= 5 && similarity >= 0.7) return { reason: 'similar', detail: `${Math.round(similarity * 100)}% alike`, score: similarity * 0.7 };

  return null;
}

/**
 * Lower-confidence guesses for a friend with no exact match, e.g. a renamed account
 * (a connection whose token is the old name "AerieHusky" for a friend now called
 * "AerieBerry" shares the word "aerie"). They are only ever offered as hints the owner
 * can pick; never preselected. Best first, at most `limit`.
 */
export function possibleMatches(displayName: string | null, candidates: readonly MatchCandidate[], limit = 3): PossibleMatch[] {
  const wanted = (displayName ?? '').trim();
  if (wanted === '') return [];

  const found: PossibleMatch[] = [];

  for (const candidate of candidates) {
    // An exact match (by name or token) belongs to matchFriend, not to the guesses.
    if ([candidate.name, ...candidate.tokens].some((value) => normalizeName(value) === normalizeName(wanted))) continue;
    const wantedCore = coreName(wanted);
    if (wantedCore.length >= MIN_CORE && [candidate.name, ...candidate.tokens].some((value) => coreName(value) === wantedCore)) continue;

    let best: PossibleMatch | null = null;

    for (const via of [candidate.name, ...candidate.tokens]) {
      const result = via ? compareNames(wanted, via) : null;
      if (result && (best === null || result.score > best.score)) best = { id: candidate.id, via, ...result };
    }

    if (best) found.push(best);
  }

  return found.sort((a, b) => b.score - a.score || a.via.localeCompare(b.via)).slice(0, limit);
}

/**
 * Like matchFriend, for a friend who goes by several names (a Discord friend has
 * your own nickname for them, a display name and a username): the first name that
 * matches anything decides, so a nickname you chose outranks their public name.
 */
export function matchFriendByNames(names: readonly (string | null)[], candidates: readonly MatchCandidate[]): FriendMatch {
  for (const name of names) {
    const match = matchFriend(name, candidates);
    if (match.kind !== 'none') return match;
  }

  return { kind: 'none' };
}

/** possibleMatches across several names: the best-scoring guess per connection, best first. */
export function possibleMatchesByNames(names: readonly (string | null)[], candidates: readonly MatchCandidate[], limit = 3): PossibleMatch[] {
  const best = new Map<string, PossibleMatch>();

  for (const name of names) {
    for (const guess of possibleMatches(name, candidates, candidates.length)) {
      const current = best.get(guess.id);
      if (!current || guess.score > current.score) best.set(guess.id, guess);
    }
  }

  return [...best.values()].sort((a, b) => b.score - a.score || a.via.localeCompare(b.via)).slice(0, limit);
}
