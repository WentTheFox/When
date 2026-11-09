import { describe, expect, it } from 'vitest';
import { stagedLabel } from '../serverCreatedFields';

describe('stagedLabel', () => {
  it('is the staged name of a field that has no encrypted label yet', () => {
    expect(stagedLabel({ label_ciphertext: null, staged_label: 'Visitor languages' })).toBe('Visitor languages');
  });

  it('is nothing once the label is encrypted, or when no name was staged', () => {
    expect(stagedLabel({ label_ciphertext: 'abc', staged_label: 'Visitor languages' })).toBeNull();
    expect(stagedLabel({ label_ciphertext: null, staged_label: null })).toBeNull();
    expect(stagedLabel({ label_ciphertext: null })).toBeNull();
  });
});
