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

/** A friend the owner has to place by hand: several connections match, or the one that matches can't take another id as it is. */
export interface ReviewItem {
  /** The server's id for the pending friend (not the VRChat id). */
  friendId: string;
  vrchatId: string;
  name: string;
  candidates: { id: string; name: string; /** Holds a different id in a single-identifier field. */ conflict: boolean }[];
}

/** 'new' creates a connection, 'skip' leaves the friend out for good, anything else is the id of the connection to add them to. */
export type ReviewChoice = 'new' | 'skip' | string;

/** Whether applying these choices maps someone onto a connection that holds another id, i.e. needs identifier -> list. */
export function needsListConversion(items: readonly ReviewItem[], choices: Readonly<Record<string, ReviewChoice>>): boolean {
  return items.some((item) => item.candidates.some((c) => c.conflict && choices[item.friendId] === c.id));
}
