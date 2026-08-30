import { describe, expect, it } from 'vitest';
import { decryptString, encryptString, exportAesKey } from '../aesgcm';
import {
  buildFragment,
  generateFragmentKey,
  importKeyFromFragment,
  unwrapKeyWithPassphrase,
  wrapKeyWithPassphrase,
} from '../fragment';

describe('share-link fragment key (default, unprotected mode)', () => {
  it('generates a key encodable into a URL-safe fragment', async () => {
    const { encoded } = await generateFragmentKey();

    expect(encoded).not.toMatch(/[+/=]/); // must be URL-safe
    expect(buildFragment(encoded)).toBe(`k=${encoded}`);
  });

  it('round-trips: encrypt with the generated key, decrypt after re-importing from the fragment string', async () => {
    const { key, encoded } = await generateFragmentKey();
    const plaintext = 'free/busy payload';

    const ciphertext = await encryptString(key, plaintext);

    const reimportedKey = await importKeyFromFragment(buildFragment(encoded));
    expect(await decryptString(reimportedKey, ciphertext)).toBe(plaintext);
  });

  it('parses a bare fragment value without the k= prefix', async () => {
    const { key, encoded } = await generateFragmentKey();
    const reimportedKey = await importKeyFromFragment(encoded);

    const raw1 = await exportAesKey(key);
    const raw2 = await exportAesKey(reimportedKey);
    expect(raw2).toEqual(raw1);
  });
});

describe('share-link passphrase protection (optional per-link upgrade)', () => {
  it('wraps and unwraps the content key with the correct passphrase', async () => {
    const { key: contentKey } = await generateFragmentKey();
    const passphrase = 'a shared secret only the viewer knows';

    const record = await wrapKeyWithPassphrase(contentKey, passphrase);
    const unwrapped = await unwrapKeyWithPassphrase(record, passphrase);

    const raw1 = await exportAesKey(contentKey);
    const raw2 = await exportAesKey(unwrapped);
    expect(raw2).toEqual(raw1);
  });

  it('the stored wrapped-key record never contains the raw content key', async () => {
    const { key: contentKey, encoded: rawContentKeyEncoded } = await generateFragmentKey();
    const record = await wrapKeyWithPassphrase(contentKey, 'correct-passphrase');

    expect(record.wrappedKey).not.toContain(rawContentKeyEncoded);
  });

  it('fails cleanly on the wrong passphrase instead of returning a garbage key', async () => {
    const { key: contentKey } = await generateFragmentKey();
    const record = await wrapKeyWithPassphrase(contentKey, 'correct-passphrase');

    await expect(unwrapKeyWithPassphrase(record, 'wrong-passphrase')).rejects.toThrow();
  });
});
