/**
 * Custom fields the SERVER created (App\Support\VisitorFieldsBackfill: the recorded visitor languages and time
 * zones, copied onto connections) have no encrypted label yet: a label is E2EE, encrypted under a per-record key in
 * the owner's key ring, which only the browser can reach. The server gives such a field a plaintext `staged_label`
 * (a fixed, generic name) and a null `label_ciphertext`. The Connections page shows the staged name meanwhile and,
 * once the vault is unlocked, encrypts it and writes it back; saving an encrypted label clears the staged one.
 */
export interface StageableDefinition {
  label_ciphertext: string | null;
  staged_label?: string | null;
}

/** The plaintext name the server staged for a field that has no encrypted label yet, or null when there is none to encrypt. */
export function stagedLabel(definition: StageableDefinition): string | null {
  return definition.label_ciphertext === null && definition.staged_label ? definition.staged_label : null;
}
