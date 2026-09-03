import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { deriveHighlightTokenKey } from '../highlightTokenKey';
import { exportAesKey } from '../aesgcm';
import { bytesToBase64 } from '../encoding';

/**
 * Proves the TS and PHP HKDF derivations actually agree: every share
 * link's viewer (client-side) and the recompute job (server-side) derive
 * its content key independently from nothing but its highlight_token/id.
 */
describe('highlight-token key derivation interop with PHP', () => {
  it('derives the exact key App\\Services\\Crypto\\HighlightTokenKey.php produces for the same token', async () => {
    const fixturePath = fileURLToPath(
      new URL('../../../../tests/Fixtures/crypto/highlight_token_key.json', import.meta.url),
    );
    const fixture = JSON.parse(readFileSync(fixturePath, 'utf-8'));

    const key = await deriveHighlightTokenKey(fixture.token);
    const rawKey = await exportAesKey(key);

    expect(bytesToBase64(rawKey)).toBe(fixture.expected_key_base64);
  });
});
