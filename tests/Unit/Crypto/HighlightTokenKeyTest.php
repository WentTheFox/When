<?php

namespace Tests\Unit\Crypto;

use App\Services\Crypto\HighlightTokenKey;
use PHPUnit\Framework\TestCase;

class HighlightTokenKeyTest extends TestCase
{
    public function test_derives_a_32_byte_key(): void
    {
        $key = HighlightTokenKey::derive('some-token');
        $this->assertSame(32, strlen($key));
    }

    public function test_is_deterministic_for_the_same_token(): void
    {
        $a = HighlightTokenKey::derive('same-token');
        $b = HighlightTokenKey::derive('same-token');
        $this->assertSame($a, $b);
    }

    public function test_produces_different_keys_for_different_tokens(): void
    {
        $a = HighlightTokenKey::derive('token-one');
        $b = HighlightTokenKey::derive('token-two');
        $this->assertNotSame($a, $b);
    }

    /** Proves TS derives the identical key — see the matching interop test on the TS side. */
    public function test_matches_the_documented_interop_fixture(): void
    {
        $fixture = json_decode(
            file_get_contents(__DIR__.'/../../Fixtures/crypto/highlight_token_key.json'),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );

        $key = HighlightTokenKey::derive($fixture['token']);

        $this->assertSame($fixture['expected_key_base64'], base64_encode($key));
    }
}
