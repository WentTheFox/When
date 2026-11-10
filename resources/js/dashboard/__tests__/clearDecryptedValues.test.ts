import { ref } from 'vue';
import { describe, expect, it } from 'vitest';
import { clearDecryptedValues } from '../clearDecryptedValues';

describe('clearDecryptedValues', () => {
  it('drops every decrypted label, option, name and alternative name', () => {
    const state = {
      sources: [{ label: 'Discord' }],
      categories: [{ label: 'Friends' }],
      definitions: [{ label: 'Pronouns', options: ['a', 'b'] }],
      names: ref({ c1: 'Alice' }),
      alternativeNames: ref({ c1: ['Al'] }),
    };

    clearDecryptedValues(state, () => false);

    expect(state.sources[0].label).toBeUndefined();
    expect(state.categories[0].label).toBeUndefined();
    expect(state.definitions[0]).toEqual({ label: undefined, options: [] });
    expect(state.names.value).toEqual({});
    expect(state.alternativeNames.value).toEqual({});
  });

  it('keeps a staged field name, which is not stored encrypted anywhere yet', () => {
    const staged = { label: 'New field', options: ['x'] };
    const state = { sources: [], categories: [], definitions: [staged], names: ref({}), alternativeNames: ref({}) };

    clearDecryptedValues(state, (d) => d === staged);

    expect(staged).toEqual({ label: 'New field', options: ['x'] });
  });
});
