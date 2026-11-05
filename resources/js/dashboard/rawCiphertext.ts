/**
 * Truncated raw ciphertext for places a component can't go (e.g. <option>
 * text): shown until the real value has been decrypted. Display only.
 */
export function rawPreview(ciphertext: string, max = 24): string {
  return ciphertext.length > max ? `${ciphertext.slice(0, max)}…` : ciphertext;
}
