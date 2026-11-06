import { describe, expect, it } from 'vitest';
import { COUNTRY_CODES, countryOptions } from '../countries';

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
});
