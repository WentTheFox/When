<?php

namespace App\Services\Crypto;

/**
 * AES-256-GCM, byte-for-byte compatible with resources/js/crypto/aesgcm.ts —
 * this is the server-side half of the §5.3 recompute boundary: the
 * recompute job encrypts the computed result with the share link's content
 * key using THIS class, and the viewer's browser decrypts it with WebCrypto
 * using the SAME share link key (from the URL fragment or unwrapped via
 * passphrase). The two implementations must agree on wire format exactly:
 *
 *   base64( iv[12 bytes] || ciphertext || tag[16 bytes] )
 *
 * WebCrypto's subtle.encrypt() appends the tag to the ciphertext
 * automatically; openssl_encrypt() returns it separately, so this class
 * concatenates iv+ciphertext+tag itself to match. If you touch this file,
 * touch aesgcm.ts's format comment too, and re-run the interop fixture test
 * in tests/Unit/Crypto/AesGcmInteropTest.php.
 */
class AesGcm
{
    private const IV_LENGTH = 12;
    private const TAG_LENGTH = 16;
    private const CIPHER = 'aes-256-gcm';

    public static function encrypt(string $rawKey, string $plaintext): string
    {
        self::assertKeyLength($rawKey);

        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $rawKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH,
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('AES-GCM encryption failed.');
        }

        return base64_encode($iv.$ciphertext.$tag);
    }

    public static function decrypt(string $rawKey, string $blob): string
    {
        self::assertKeyLength($rawKey);

        $combined = base64_decode($blob, true);

        if ($combined === false || strlen($combined) <= self::IV_LENGTH + self::TAG_LENGTH) {
            throw new DecryptionFailedException();
        }

        $iv = substr($combined, 0, self::IV_LENGTH);
        $tag = substr($combined, -self::TAG_LENGTH);
        $ciphertext = substr($combined, self::IV_LENGTH, -self::TAG_LENGTH);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $rawKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
        );

        if ($plaintext === false) {
            throw new DecryptionFailedException();
        }

        return $plaintext;
    }

    private static function assertKeyLength(string $rawKey): void
    {
        if (strlen($rawKey) !== 32) {
            throw new \InvalidArgumentException('AES-256 key must be 32 bytes, got '.strlen($rawKey).'.');
        }
    }
}
