import { describe, expect, it } from 'vitest';
import { bindField } from '../fieldBinding';

describe('bindField', () => {
  it('reads a field with no value yet as the empty string, so "(unset)" is the selected option', () => {
    expect(bindField({}, 'tier').modelValue).toBe('');
    expect(bindField({ tier: undefined }, 'tier').modelValue).toBe('');
  });

  it('reads an existing value as is, including the string "false"', () => {
    expect(bindField({ tier: 'Gold' }, 'tier').modelValue).toBe('Gold');
    expect(bindField({ flag: 'false' }, 'flag').modelValue).toBe('false');
  });

  it('writes the chosen value back into the map', () => {
    const values: Record<string, string | undefined> = {};
    bindField(values, 'tier')['onUpdate:modelValue']('Silver');
    expect(values).toEqual({ tier: 'Silver' });
  });

  it('stores an empty selection as the empty string, never undefined or "null"', () => {
    const values: Record<string, string | undefined> = { tier: 'Gold' };
    bindField(values, 'tier')['onUpdate:modelValue'](null);
    expect(values.tier).toBe('');
    bindField(values, 'tier')['onUpdate:modelValue'](undefined);
    expect(values.tier).toBe('');
  });

  it('only touches its own field', () => {
    const values: Record<string, string | undefined> = { a: '1', b: '2' };
    bindField(values, 'a')['onUpdate:modelValue']('9');
    expect(values).toEqual({ a: '9', b: '2' });
  });
});
