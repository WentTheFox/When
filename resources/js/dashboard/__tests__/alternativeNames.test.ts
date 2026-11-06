import { describe, expect, it } from 'vitest';
import { matchConnection, parseAlternativeNames } from '../alternativeNames';

describe('parseAlternativeNames', () => {
  it('splits multi-line text, trimming and dropping blanks and repeats', () => {
    expect(parseAlternativeNames(' Ash \r\n\nBirch\nAsh')).toEqual(['Ash', 'Birch']);
  });

  it('cleans an array the same way', () => {
    expect(parseAlternativeNames(['Ash', ' ', ' Birch ', 'Ash'])).toEqual(['Ash', 'Birch']);
  });

  it('treats a missing value as no names', () => {
    expect(parseAlternativeNames(null)).toEqual([]);
    expect(parseAlternativeNames(undefined)).toEqual([]);
  });
});

describe('matchConnection', () => {
  it('matches everything on an empty query', () => {
    expect(matchConnection('Maple', [], '')).toEqual({ matches: true, via: null });
  });

  it('matches the name without reporting an alternative, even if one would match too', () => {
    expect(matchConnection('Maple', ['Maple Sparrow'], 'maple')).toEqual({ matches: true, via: null });
  });

  it('matches an alternative name case-insensitively and reports it', () => {
    expect(matchConnection('Maple', ['Old Birch'], 'birch')).toEqual({ matches: true, via: 'Old Birch' });
  });

  it('does not match when neither does', () => {
    expect(matchConnection('Maple', ['Old Birch'], 'oak')).toEqual({ matches: false, via: null });
  });
});
