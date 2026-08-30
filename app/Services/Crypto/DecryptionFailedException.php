<?php

namespace App\Services\Crypto;

/** Wrong key or tampered/corrupted ciphertext — never silently returns garbage. */
class DecryptionFailedException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Decryption failed: wrong key or corrupted/tampered ciphertext.');
    }
}
