import { describe, expect, it } from 'vitest';
import { COUNTRY_CODES, countryKeywords, countryOptions, flagIconCode, flagIconKey, searchRank } from '../countries';

describe('countries', () => {
  it('has no duplicate codes', () => {
    expect(new Set(COUNTRY_CODES).size).toBe(COUNTRY_CODES.length);
  });

  it('leads with (unset) and names every country', () => {
    const options = countryOptions('en');
    expect(options[0]).toEqual({ value: '', text: '(unset)' });
    expect(options).toContainEqual({ value: 'DE', text: 'Germany' });
    expect(options).toHaveLength(COUNTRY_CODES.length + 1);
  });

  it('ranks exact aliases above substring hits', () => {
    const rankOf = (query: string, code: string) => {
      const name = countryOptions('en').find((o) => o.value === code)!.text;
      return searchRank(query, [name], countryKeywords(code));
    };

    expect(rankOf('uk', 'GB')).toBe(0);
    expect(rankOf('uk', 'UA')).toBe(1);
    expect(rankOf('us', 'US')).toBe(0);
    expect(rankOf('us', 'AT')).toBe(2);
    expect(rankOf('usa', 'US')).toBe(0);
    expect(rankOf('zzz', 'US')).toBeNull();
  });

  it('round-trips flag icon keys and rejects unknown ones', () => {
    expect(flagIconKey('GB')).toBe('flag:gb');
    expect(flagIconCode('flag:gb')).toBe('GB');
    expect(flagIconCode('flag:zz')).toBeNull();
    expect(flagIconCode('star')).toBeNull();
  });
});
