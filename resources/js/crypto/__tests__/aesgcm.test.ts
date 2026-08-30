import { describe, expect, it } from 'vitest';
import {
  DecryptionFailedError,
  decryptString,
  encryptString,
  generateAesKey,
} from '../aesgcm';

describe('AES-256-GCM string encryption', () => {
  it('round-trips plaintext through encrypt/decrypt', async () => {
    const key = await generateAesKey();
    const plaintext = 'https://calendar.example.com/secret-feed.ics';

    const ciphertext = await encryptString(key, plaintext);
    expect(ciphertext).not.toContain(plaintext);

    const decrypted = await decryptString(key, ciphertext);
    expect(decrypted).toBe(plaintext);
  });

  it('produces different ciphertext for the same plaintext (random IV)', async () => {
    const key = await generateAesKey();
    const plaintext = 'same input';

    const a = await encryptString(key, plaintext);
    const b = await encryptString(key, plaintext);

    expect(a).not.toBe(b);
  });

  it('throws a clean DecryptionFailedError on the wrong key, never garbage output', async () => {
    const key = await generateAesKey();
    const wrongKey = await generateAesKey();
    const ciphertext = await encryptString(key, 'sensitive value');

    await expect(decryptString(wrongKey, ciphertext)).rejects.toBeInstanceOf(
      DecryptionFailedError,
    );
  });

  it('throws on tampered ciphertext instead of returning corrupted plaintext', async () => {
    const key = await generateAesKey();
    const ciphertext = await encryptString(key, 'sensitive value');

    const bytes = Buffer.from(ciphertext, 'base64');
    bytes[bytes.length - 1] ^= 0xff; // flip a bit in the auth tag
    const tampered = bytes.toString('base64');

    await expect(decryptString(key, tampered)).rejects.toBeInstanceOf(DecryptionFailedError);
  });

  it('handles empty string plaintext', async () => {
    const key = await generateAesKey();
    const ciphertext = await encryptString(key, '');
    expect(await decryptString(key, ciphertext)).toBe('');
  });

  it('handles unicode plaintext', async () => {
    const key = await generateAesKey();
    const plaintext = '🦊 Went the Fox — café, naïve, 日本語';
    const ciphertext = await encryptString(key, plaintext);
    expect(await decryptString(key, ciphertext)).toBe(plaintext);
  });
});
