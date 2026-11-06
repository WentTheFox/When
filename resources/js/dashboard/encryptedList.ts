/**
 * The "list" custom field type: one entry per line, like an identifier list, but a regular E2EE value.
 *
 * The whole list is one ciphertext in value_ciphertext (the newline-joined entries), so the server can't
 * tell how many entries there are, only that a value exists. The ciphertext's size would still give away
 * roughly how much text the list holds, so before encrypting the text is padded with blank lines up to the
 * next LIST_PAD_BUCKET characters. Blank lines are dropped whenever a list is read, so padding is invisible
 * everywhere except in the ciphertext's length.
 */

/** Padded plaintext length is always a multiple of this. */
export const LIST_PAD_BUCKET = 256;

/** The entries in a list value: one per line, trimmed, blanks (and so any padding) and repeats dropped. */
export function listEntries(text: string | null | undefined): string[] {
  const seen = new Set<string>();

  for (const line of (text ?? '').split(/\r?\n/)) {
    const entry = line.trim();
    if (entry !== '') seen.add(entry);
  }

  return [...seen];
}

/** What the edit form shows: the entries without padding. */
export function listText(text: string | null | undefined): string {
  return listEntries(text).join('\n');
}

/** What gets encrypted: the entries, padded to a multiple of LIST_PAD_BUCKET characters. '' when there are none. */
export function paddedListText(text: string | null | undefined): string {
  const body = listText(text);
  if (body === '') return '';

  const length = Math.ceil((body.length + 1) / LIST_PAD_BUCKET) * LIST_PAD_BUCKET;

  return body.padEnd(length, '\n');
}
