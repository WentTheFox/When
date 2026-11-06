import { describe, expect, it } from 'vitest';
import { appendNote } from '../vrchatNotes';

describe('appendNote', () => {
  it('is just the note when there were no notes', () => {
    expect(appendNote('', 'Likes tea')).toEqual({ text: 'Likes tea', changed: true });
    expect(appendNote(null, 'Likes tea')).toEqual({ text: 'Likes tea', changed: true });
    expect(appendNote('  \n ', 'Likes tea')).toEqual({ text: 'Likes tea', changed: true });
  });

  it('adds the note on a new line after existing notes', () => {
    expect(appendNote('Met at a meetup', 'Likes tea')).toEqual({ text: 'Met at a meetup\nLikes tea', changed: true });
  });

  it('does not stack blank lines when the notes already end with a newline', () => {
    expect(appendNote('Met at a meetup\n\n', 'Likes tea').text).toBe('Met at a meetup\nLikes tea');
  });

  it('keeps a multi-line note as it was written', () => {
    expect(appendNote('First', 'Line one\nLine two').text).toBe('First\nLine one\nLine two');
  });

  it('never adds a note that is already there, so importing twice is harmless', () => {
    const once = appendNote('Met at a meetup', 'Likes tea');
    expect(appendNote(once.text, 'Likes tea')).toEqual({ text: once.text, changed: false });
    expect(appendNote('Typed by hand: Likes tea, really', 'Likes tea').changed).toBe(false);
  });

  it('ignores an empty note and trims the one it adds', () => {
    expect(appendNote('Existing', '   ')).toEqual({ text: 'Existing', changed: false });
    expect(appendNote('Existing', '  padded  ').text).toBe('Existing\npadded');
  });
});
