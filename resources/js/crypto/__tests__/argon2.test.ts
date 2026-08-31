import { describe, expect, it } from 'vitest';
import { ARGON2ID_PROFILE, deriveKeyFromPassphrase, deriveLoginVerifier, generateSalt } from '../argon2';
import { importAesKey } from '../aesgcm';

describe('Argon2id vault key derivation', () => {
  it('matches the documented OWASP-recommended profile', () => {
    expect(ARGON2ID_PROFILE).toEqual({
      memorySize: 19456,
      iterations: 2,
      parallelism: 1,
      hashLength: 32,
    });
  });

  it('derives a 32-byte key usable as an AES-256 key', async () => {
    const salt = generateSalt();
    const { keyBytes } = await deriveKeyFromPassphrase('correct horse battery staple', salt);

    expect(keyBytes).toHaveLength(32);
    await expect(importAesKey(keyBytes)).resolves.toBeDefined();
  });

  it('is deterministic for the same passphrase + salt', async () => {
    const salt = generateSalt();
    const a = await deriveKeyFromPassphrase('same passphrase', salt);
    const b = await deriveKeyFromPassphrase('same passphrase', salt);

    expect(Array.from(a.keyBytes)).toEqual(Array.from(b.keyBytes));
  });

  it('produces a different key for a different passphrase', async () => {
    const salt = generateSalt();
    const a = await deriveKeyFromPassphrase('passphrase one', salt);
    const b = await deriveKeyFromPassphrase('passphrase two', salt);

    expect(Array.from(a.keyBytes)).not.toEqual(Array.from(b.keyBytes));
  });

  it('produces a different key for a different salt, same passphrase', async () => {
    const a = await deriveKeyFromPassphrase('same passphrase', generateSalt());
    const b = await deriveKeyFromPassphrase('same passphrase', generateSalt());

    expect(Array.from(a.keyBytes)).not.toEqual(Array.from(b.keyBytes));
  });

  it('rejects an empty passphrase', async () => {
    await expect(deriveKeyFromPassphrase('', generateSalt())).rejects.toThrow();
  });

  it('generates salts of the requested length, base64-encoded', () => {
    const salt = generateSalt(16);
    expect(Buffer.from(salt, 'base64')).toHaveLength(16);
  });
});

describe('login verifier derivation (single master-password split, §0.3)', () => {
  it('is deterministic for the same master password + email', async () => {
    const a = await deriveLoginVerifier('correct horse battery staple', 'Fox@Example.com');
    const b = await deriveLoginVerifier('correct horse battery staple', 'Fox@Example.com');

    expect(a).toEqual(b);
  });

  it('is case- and whitespace-insensitive on the email, matching how emails are normally compared', async () => {
    const a = await deriveLoginVerifier('correct horse battery staple', 'fox@example.com');
    const b = await deriveLoginVerifier('correct horse battery staple', '  Fox@Example.com  ');

    expect(a).toEqual(b);
  });

  it('produces a different verifier for a different master password', async () => {
    const a = await deriveLoginVerifier('master password one', 'fox@example.com');
    const b = await deriveLoginVerifier('master password two', 'fox@example.com');

    expect(a).not.toEqual(b);
  });

  it('produces a different verifier for a different email, same master password', async () => {
    const a = await deriveLoginVerifier('correct horse battery staple', 'fox@example.com');
    const b = await deriveLoginVerifier('correct horse battery staple', 'wolf@example.com');

    expect(a).not.toEqual(b);
  });

  it('produces a value independent of the vault key derived from the same master password', async () => {
    const email = 'fox@example.com';
    const vaultSalt = generateSalt();

    const verifier = await deriveLoginVerifier('correct horse battery staple', email);
    const { keyBytes: vaultKey } = await deriveKeyFromPassphrase('correct horse battery staple', vaultSalt);

    expect(verifier).not.toEqual(Buffer.from(vaultKey).toString('base64'));
  });
});
