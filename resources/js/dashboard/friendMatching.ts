/**
 * Matching a VRChat friend to a connection you already have, so that adding
 * existing friends doesn't duplicate people. It runs in the browser because
 * connection names are end-to-end encrypted.
 *
 * A friend matches a connection when their VRChat display name equals the
 * connection's name, or equals one of its highlight tokens, ignoring case,
 * surrounding space and repeated spaces. Exact only: a fuzzy match would link
 * the wrong person, which is worse than a duplicate.
 */

export interface MatchCandidate {
  id: string;
  /** The decrypted connection name ('' when it couldn't be decrypted). */
  name: string;
  /** The connection's highlight tokens (plaintext, §0.2 tier). */
  tokens: string[];
}

export type FriendMatch =
  | { kind: 'none' }
  | { kind: 'one'; connectionId: string; by: 'name' | 'token' }
  | { kind: 'several'; connectionIds: string[] };

export function normalizeName(value: string): string {
  return value.normalize('NFKC').trim().replace(/\s+/g, ' ').toLowerCase();
}

/** Zero matches: create a connection. One: link to it. Several: ambiguous, leave it to the owner. */
export function matchFriend(displayName: string | null, candidates: readonly MatchCandidate[]): FriendMatch {
  const wanted = normalizeName(displayName ?? '');
  if (wanted === '') return { kind: 'none' };

  const hits: { id: string; by: 'name' | 'token' }[] = [];

  for (const candidate of candidates) {
    if (normalizeName(candidate.name) === wanted) {
      hits.push({ id: candidate.id, by: 'name' });
    } else if (candidate.tokens.some((token) => normalizeName(token) === wanted)) {
      hits.push({ id: candidate.id, by: 'token' });
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

/** Words of a name, split on punctuation, spaces and camelCase humps: "AerieBerry" gives aerie, berry. Short words are noise. */
function wordsOf(value: string): string[] {
  return value
    .normalize('NFKC')
    .replace(/(\p{Ll})(\p{Lu})/gu, '$1 $2')
    .split(/[^\p{L}\p{N}]+/u)
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

  const shared = wordsOf(wanted).find((w) => wordsOf(other).includes(w));
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

    let best: PossibleMatch | null = null;

    for (const via of [candidate.name, ...candidate.tokens]) {
      const result = via ? compareNames(wanted, via) : null;
      if (result && (best === null || result.score > best.score)) best = { id: candidate.id, via, ...result };
    }

    if (best) found.push(best);
  }

  return found.sort((a, b) => b.score - a.score || a.via.localeCompare(b.via)).slice(0, limit);
}
