<?php

namespace Tests\Unit\Crypto;

use App\Services\Crypto\Argon2id;
use PHPUnit\Framework\TestCase;

class Argon2idTest extends TestCase
{
    public function test_derives_a_32_byte_key(): void
    {
        $key = Argon2id::derive('a passphrase', base64_encode(random_bytes(16)));
        $this->assertSame(32, strlen($key));
    }

    public function test_is_deterministic_for_the_same_passphrase_and_salt(): void
    {
        $salt = base64_encode(random_bytes(16));
        $a = Argon2id::derive('same passphrase', $salt);
        $b = Argon2id::derive('same passphrase', $salt);
        $this->assertSame($a, $b);
    }

    public function test_produces_a_different_key_for_a_different_passphrase(): void
    {
        $salt = base64_encode(random_bytes(16));
        $a = Argon2id::derive('passphrase one', $salt);
        $b = Argon2id::derive('passphrase two', $salt);
        $this->assertNotSame($a, $b);
    }

    public function test_rejects_a_salt_that_is_not_16_bytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Argon2id::derive('a passphrase', base64_encode(random_bytes(8)));
    }

    /** Proves libsodium and hash-wasm derive the identical key — see the matching TS interop test. */
    public function test_matches_the_documented_interop_fixture_from_hash_wasm(): void
    {
        $fixture = json_decode(
            file_get_contents(__DIR__.'/../../Fixtures/crypto/argon2id.json'),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );

        $key = Argon2id::derive($fixture['passphrase'], $fixture['salt_base64']);

        $this->assertSame($fixture['expected_key_base64'], base64_encode($key));
    }
}
