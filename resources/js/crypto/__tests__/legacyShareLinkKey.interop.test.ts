import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { deriveLegacyShareLinkKey } from '../legacyShareLinkKey';
import { exportAesKey } from '../aesgcm';
import { bytesToBase64 } from '../encoding';

/**
 * Proves the TS and PHP HKDF derivations actually agree (§0.5): given the
 * same token, both must produce the identical AES key, since a migrated
 * share link's viewer (client-side) and the recompute job (server-side)
 * derive it independently from nothing but the token.
 */
describe('legacy share-link key derivation interop with PHP', () => {
  it('derives the exact key App\\Services\\Crypto\\LegacyShareLinkKey.php produces for the same token', async () => {
    const fixturePath = fileURLToPath(
      new URL('../../../../tests/Fixtures/crypto/legacy_share_link_key.json', import.meta.url),
    );
    const fixture = JSON.parse(readFileSync(fixturePath, 'utf-8'));

    const key = await deriveLegacyShareLinkKey(fixture.token);
    const rawKey = await exportAesKey(key);

    expect(bytesToBase64(rawKey)).toBe(fixture.expected_key_base64);
  });
});
