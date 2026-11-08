/**
 * Reads friends and blocked users out of text the owner pasted (their own copy of Discord's relationships list),
 * in the browser, and keeps only the few fields we store, each checked the way Discord limits it: names at most
 * 32 characters without control characters, an avatar as a hash, an ID as 17 to 20 digits. A field that fails is
 * left out (the person is still imported); an entry without a valid ID is skipped. The server checks all of it again.
 * The owner's paste is trusted as their own data, so what passes is stored, but marked as not yet confirmed by Discord.
 */

/** Discord relationship types we import. */
const FRIEND = 1;
const BLOCKED = 2;

const ID = /^\d{17,20}$/;
const AVATAR = /^(a_)?[0-9a-f]{32}$/;
const NAME_MAX = 32;

/** The most entries one import sends (the server enforces the same cap). */
export const IMPORT_MAX = 2000;

export interface ImportEntry {
  id: string;
  kind: 'friend' | 'blocked';
  username: string | null;
  global_name: string | null;
  /** Your own nickname for a friend. */
  nickname: string | null;
  /** The avatar hash, never a URL. */
  avatar: string | null;
}

export interface ExtractedRelationships {
  entries: ImportEntry[];
  friends: number;
  blocked: number;
  /** More people than the cap: the rest were left out. */
  truncated: boolean;
}

function name(value: unknown, min = 1): string | null {
  if (typeof value !== 'string') return null;

  const text = value.trim();
  const length = [...text].length;

  // eslint-disable-next-line no-control-regex
  return length >= min && length <= NAME_MAX && !/[\u0000-\u001f\u007f-\u009f]/.test(text) ? text : null;
}

/** Throws an Error with a message for the owner when the text is not a relationships list. */
export function extractRelationships(text: string): ExtractedRelationships {
  let data: unknown;

  try {
    data = JSON.parse(text);
  } catch {
    throw new Error('That is not valid JSON.');
  }

  if (!Array.isArray(data)) {
    throw new Error('Expected a list of relationships.');
  }

  const seen = new Set<string>();
  const entries: ImportEntry[] = [];

  for (const item of data as unknown[]) {
    if (!item || typeof item !== 'object') continue;

    const row = item as { type?: unknown; id?: unknown; nickname?: unknown; user?: Record<string, unknown> | null };
    const kind = row.type === FRIEND ? 'friend' : row.type === BLOCKED ? 'blocked' : null;

    if (kind === null) continue;

    // The user's id sits in `user.id`; the relationship's own `id` is the same number in Discord's API.
    const id = [row.user?.id, row.id].find((v): v is string => typeof v === 'string' && ID.test(v));

    if (!id || seen.has(id)) continue;

    seen.add(id);

    const avatar = row.user?.avatar;

    entries.push({
      id,
      kind,
      username: name(row.user?.username, 2),
      global_name: name(row.user?.global_name),
      nickname: kind === 'friend' ? name(row.nickname) : null,
      avatar: typeof avatar === 'string' && AVATAR.test(avatar) ? avatar : null,
    });
  }

  return {
    entries: entries.slice(0, IMPORT_MAX),
    friends: entries.slice(0, IMPORT_MAX).filter((e) => e.kind === 'friend').length,
    blocked: entries.slice(0, IMPORT_MAX).filter((e) => e.kind === 'blocked').length,
    truncated: entries.length > IMPORT_MAX,
  };
}
