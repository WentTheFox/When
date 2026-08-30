<?php

namespace Tests\Unit\Crypto;

use App\Services\Crypto\AesGcm;
use App\Services\Crypto\DecryptionFailedException;
use PHPUnit\Framework\TestCase;

class AesGcmTest extends TestCase
{
    public function test_round_trips_plaintext(): void
    {
        $key = random_bytes(32);
        $plaintext = 'https://calendar.example.com/secret-feed.ics';

        $ciphertext = AesGcm::encrypt($key, $plaintext);
        $this->assertStringNotContainsString($plaintext, $ciphertext);

        $this->assertSame($plaintext, AesGcm::decrypt($key, $ciphertext));
    }

    public function test_produces_different_ciphertext_for_the_same_plaintext(): void
    {
        $key = random_bytes(32);
        $a = AesGcm::encrypt($key, 'same input');
        $b = AesGcm::encrypt($key, 'same input');

        $this->assertNotSame($a, $b);
    }

    public function test_throws_on_the_wrong_key(): void
    {
        $key = random_bytes(32);
        $wrongKey = random_bytes(32);
        $ciphertext = AesGcm::encrypt($key, 'sensitive value');

        $this->expectException(DecryptionFailedException::class);
        AesGcm::decrypt($wrongKey, $ciphertext);
    }

    public function test_throws_on_tampered_ciphertext(): void
    {
        $key = random_bytes(32);
        $ciphertext = AesGcm::encrypt($key, 'sensitive value');

        $bytes = base64_decode($ciphertext, true);
        $bytes[strlen($bytes) - 1] = chr(ord($bytes[strlen($bytes) - 1]) ^ 0xFF);
        $tampered = base64_encode($bytes);

        $this->expectException(DecryptionFailedException::class);
        AesGcm::decrypt($key, $tampered);
    }

    public function test_rejects_a_key_that_is_not_32_bytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AesGcm::encrypt(random_bytes(16), 'plaintext');
    }
}
