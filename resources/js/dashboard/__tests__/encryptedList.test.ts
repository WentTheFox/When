import { describe, expect, it } from 'vitest';
import { LIST_PAD_BUCKET, listEntries, listText, paddedListText } from '../encryptedList';

describe('encryptedList', () => {
  it('reads entries one per line, trimmed, without blanks or repeats', () => {
    expect(listEntries(' Ash \r\n\nBirch\nAsh\n\n\n')).toEqual(['Ash', 'Birch']);
    expect(listEntries(null)).toEqual([]);
  });

  it('shows the entries without padding', () => {
    expect(listText(paddedListText('Ash\nBirch'))).toBe('Ash\nBirch');
  });

  it('pads to a multiple of the bucket so the length says little', () => {
    const one = paddedListText('Ash');
    const three = paddedListText('Ash\nBirch\nCedar');

    expect(one.length).toBe(LIST_PAD_BUCKET);
    expect(three.length).toBe(LIST_PAD_BUCKET);
    expect(paddedListText('x'.repeat(LIST_PAD_BUCKET)).length).toBe(LIST_PAD_BUCKET * 2);
  });

  it('keeps every entry through padding', () => {
    expect(listEntries(paddedListText('Ash\nBirch'))).toEqual(['Ash', 'Birch']);
  });

  it('is empty for an empty list', () => {
    expect(paddedListText(' \n ')).toBe('');
  });
});
