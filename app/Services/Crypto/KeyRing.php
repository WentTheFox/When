<?php

namespace App\Services\Crypto;

/**
 * PHP-side mirror of resources/js/crypto/keyring.ts, for the operator CLI
 * only (see Argon2id.php's doc comment — same reasoning applies). A key
 * ring is a JSON object of recordId -> base64 raw AES key, itself encrypted
 * with the vault key via AesGcm.
 */
class KeyRing
{
    /** @return array<string, string> recordId -> base64 raw key */
    public static function decrypt(string $vaultKey, ?string $ciphertext): array
    {
        if ($ciphertext === null) {
            return [];
        }

        $json = AesGcm::decrypt($vaultKey, $ciphertext);

        return json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param  array<string, string>  $ring */
    public static function encrypt(string $vaultKey, array $ring): string
    {
        return AesGcm::encrypt($vaultKey, json_encode($ring));
    }

    /**
     * Returns the record's raw key, generating and adding a fresh one to
     * the ring if it isn't already present.
     *
     * @param  array<string, string>  $ring
     * @return array{0: string, 1: array<string, string>} [rawKey, updatedRing]
     */
    public static function getOrCreateKey(array $ring, string $recordId): array
    {
        if (isset($ring[$recordId])) {
            return [base64_decode($ring[$recordId], true), $ring];
        }

        $rawKey = random_bytes(32);
        $ring[$recordId] = base64_encode($rawKey);

        return [$rawKey, $ring];
    }
}
