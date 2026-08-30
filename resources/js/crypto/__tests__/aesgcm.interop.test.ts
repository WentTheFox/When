import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { decryptString } from '../aesgcm';
import { base64ToBytes } from '../encoding';
import { importAesKey } from '../aesgcm';

/**
 * Proves the TS and PHP AES-256-GCM implementations actually agree on wire
 * format (§5.3 depends on this: the server encrypts computed availability
 * results, the browser decrypts them). The reverse direction is covered by
 * tests/Unit/Crypto/AesGcmInteropTest.php against the sibling fixture.
 *
 * Regenerate php_encrypted.json only if App\Services\Crypto\AesGcm's wire
 * format changes — see that class's doc comment for the command.
 */
describe('AES-256-GCM interop with the PHP implementation', () => {
  it('decrypts ciphertext produced by AesGcm.php', async () => {
    const fixturePath = fileURLToPath(
      new URL('../../../../tests/Fixtures/crypto/php_encrypted.json', import.meta.url),
    );
    const fixture = JSON.parse(readFileSync(fixturePath, 'utf-8'));

    const key = await importAesKey(base64ToBytes(fixture.key_base64));
    const decrypted = await decryptString(key, fixture.ciphertext_base64);

    expect(decrypted).toBe(fixture.plaintext);
  });
});
