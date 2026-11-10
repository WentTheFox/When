import type { Ref } from 'vue';

interface Labelled { label?: string }
interface Definition extends Labelled { options: string[] }

interface DecryptedState<D extends Definition> {
  sources: Labelled[];
  categories: Labelled[];
  definitions: D[];
  names: Ref<Record<string, string>>;
  alternativeNames: Ref<Record<string, string[]>>;
}

/**
 * Drops every decrypted value the Connections page holds, so nothing readable stays on screen once the vault is
 * locked again. The next unlock decrypts them from the ciphertext again.
 *
 * `isStaged` marks a field whose name has not been encrypted yet: that plaintext exists nowhere else, so it stays.
 */
export function clearDecryptedValues<D extends Definition>(state: DecryptedState<D>, isStaged: (definition: D) => boolean): void {
  for (const source of state.sources) source.label = undefined;
  for (const category of state.categories) category.label = undefined;
  for (const definition of state.definitions) {
    if (isStaged(definition)) continue;
    definition.label = undefined;
    definition.options = [];
  }
  state.names.value = {};
  state.alternativeNames.value = {};
}
