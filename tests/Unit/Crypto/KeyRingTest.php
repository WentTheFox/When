<?php

namespace Tests\Unit\Crypto;

use App\Services\Crypto\KeyRing;
use PHPUnit\Framework\TestCase;

class KeyRingTest extends TestCase
{
    public function test_round_trips_an_empty_ring(): void
    {
        $vaultKey = random_bytes(32);
        $ciphertext = KeyRing::encrypt($vaultKey, []);

        $this->assertSame([], KeyRing::decrypt($vaultKey, $ciphertext));
    }

    public function test_decrypting_a_null_ciphertext_returns_an_empty_ring(): void
    {
        $this->assertSame([], KeyRing::decrypt(random_bytes(32), null));
    }

    public function test_get_or_create_key_generates_a_new_key_when_absent(): void
    {
        [$rawKey, $ring] = KeyRing::getOrCreateKey([], 'share-link-1');

        $this->assertSame(32, strlen($rawKey));
        $this->assertArrayHasKey('share-link-1', $ring);
        $this->assertSame($rawKey, base64_decode($ring['share-link-1'], true));
    }

    public function test_get_or_create_key_returns_the_existing_key_when_present(): void
    {
        $existingKey = random_bytes(32);
        $ring = ['share-link-1' => base64_encode($existingKey)];

        [$rawKey, $updatedRing] = KeyRing::getOrCreateKey($ring, 'share-link-1');

        $this->assertSame($existingKey, $rawKey);
        $this->assertSame($ring, $updatedRing);
    }

    public function test_round_trips_a_ring_with_multiple_keys_through_the_full_cycle(): void
    {
        $vaultKey = random_bytes(32);

        [, $ring] = KeyRing::getOrCreateKey([], 'a');
        [, $ring] = KeyRing::getOrCreateKey($ring, 'b');

        $ciphertext = KeyRing::encrypt($vaultKey, $ring);
        $decryptedRing = KeyRing::decrypt($vaultKey, $ciphertext);

        $this->assertSame($ring, $decryptedRing);
    }
}
