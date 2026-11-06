<?php

namespace App\Services\Vrcx;

/**
 * A VRCX call failed. The message is safe to show the owner: it never contains the API key.
 * `status` is the HTTP status VRCX answered with, null when it could not be reached at all.
 */
class VrcxException extends \RuntimeException
{
    public function __construct(string $message, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }

    /** VRCX itself failed (5xx): about the request, not about the key or the address. */
    public function isServerError(): bool
    {
        return $this->status !== null && $this->status >= 500;
    }
}
