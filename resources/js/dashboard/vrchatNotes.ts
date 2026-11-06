/**
 * Carrying a VRChat note over to a connection's notes. Connection notes are
 * end-to-end encrypted, so this runs in the browser on the decrypted text.
 */

export interface AppendedNote {
  text: string;
  /** False when the note was already in the notes, so nothing needs saving. */
  changed: boolean;
}

/**
 * Appends a VRChat note to existing notes on a new line (the note alone when there
 * were none). A note whose text is already in there is not added again, so
 * importing twice, or after typing it in by hand, can't duplicate it.
 */
export function appendNote(existing: string | null | undefined, note: string): AppendedNote {
  const current = (existing ?? '').replace(/\s+$/, '');
  const addition = note.trim();

  if (addition === '' || (current !== '' && current.includes(addition))) {
    return { text: existing ?? '', changed: false };
  }

  return { text: current === '' ? addition : `${current}\n${addition}`, changed: true };
}
