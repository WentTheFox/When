import { describe, expect, it } from 'vitest';
import { generateAesKey } from '../aesgcm';
import { deriveKeyFromPassphrase, generateSalt } from '../argon2';
import {
  decryptKeyRing,
  emptyKeyRing,
  encryptKeyRing,
  getKeyFromRing,
  putKeyInRing,
  removeKeyFromRing,
} from '../keyring';

async function makeVaultKey() {
  const salt = generateSalt();
  const { keyBytes } = await deriveKeyFromPassphrase('test passphrase', salt);
  return crypto.subtle.importKey('raw', keyBytes, { name: 'AES-GCM' }, false, [
    'encrypt',
    'decrypt',
  ]);
}

describe('key ring wrap/unwrap', () => {
  it('round-trips an empty ring', async () => {
    const vaultKey = await makeVaultKey();
    const ciphertext = await encryptKeyRing(vaultKey, emptyKeyRing());
    expect(await decryptKeyRing(vaultKey, ciphertext)).toEqual({});
  });

  it('round-trips a ring with multiple record keys', async () => {
    const vaultKey = await makeVaultKey();
    let ring = emptyKeyRing();

    const connectionKey = await generateAesKey();
    const shareLinkKey = await generateAesKey();

    ring = await putKeyInRing(ring, 'connection-1', connectionKey);
    ring = await putKeyInRing(ring, 'share-link-1', shareLinkKey);

    const ciphertext = await encryptKeyRing(vaultKey, ring);
    const decryptedRing = await decryptKeyRing(vaultKey, ciphertext);

    const recoveredConnectionKey = await getKeyFromRing(decryptedRing, 'connection-1');
    const raw1 = await crypto.subtle.exportKey('raw', connectionKey);
    const raw2 = await crypto.subtle.exportKey('raw', recoveredConnectionKey);
    expect(new Uint8Array<ArrayBuffer>(raw2)).toEqual(new Uint8Array<ArrayBuffer>(raw1));
  });

  it('allows removing a key from the ring', async () => {
    let ring = emptyKeyRing();
    ring = await putKeyInRing(ring, 'a', await generateAesKey());
    ring = await putKeyInRing(ring, 'b', await generateAesKey());

    ring = removeKeyFromRing(ring, 'a');

    expect(Object.keys(ring)).toEqual(['b']);
    await expect(getKeyFromRing(ring, 'a')).rejects.toThrow();
  });

  it('fails cleanly when unwrapping the ring with the wrong vault key', async () => {
    const vaultKey = await makeVaultKey();
    const wrongVaultKey = await makeVaultKey();

    const ciphertext = await encryptKeyRing(vaultKey, emptyKeyRing());

    await expect(decryptKeyRing(wrongVaultKey, ciphertext)).rejects.toThrow();
  });
});
