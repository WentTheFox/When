<?php

namespace Tests\Unit\Crypto;

use App\Services\Crypto\AesGcm;
use PHPUnit\Framework\TestCase;

/**
 * Proves the PHP and TS AES-256-GCM implementations actually agree on wire
 * format (§5.3 depends on this: the server encrypts computed availability
 * results, the browser decrypts them). The reverse direction — PHP-
 * encrypted ciphertext decrypting correctly in TS — is covered by
 * resources/js/crypto/__tests__/aesgcm.interop.test.ts against the sibling
 * fixture generated alongside this one.
 *
 * Regenerate ts_encrypted.json with:
 *   npx tsx scripts/generate-ts-crypto-fixture.mjs
 * only if resources/js/crypto/aesgcm.ts's wire format changes.
 */
class AesGcmInteropTest extends TestCase
{
    public function test_php_decrypts_ciphertext_produced_by_the_ts_implementation(): void
    {
        $fixture = json_decode(
            file_get_contents(__DIR__.'/../../Fixtures/crypto/ts_encrypted.json'),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );

        $key = base64_decode($fixture['key_base64'], true);
        $decrypted = AesGcm::decrypt($key, $fixture['ciphertext_base64']);

        $this->assertSame($fixture['plaintext'], $decrypted);
    }
}
