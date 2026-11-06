<?php

namespace App\Services\Discord;

/**
 * A Discord call failed. The message is safe to show the owner: it never contains a token or the client secret.
 * `status` is the HTTP status Discord answered with, null when it could not be reached at all.
 * `authRevoked` means the owner's grant is gone (token revoked/expired for good): they must reconnect.
 */
class DiscordException extends \RuntimeException
{
    public function __construct(string $message, public readonly ?int $status = null, public readonly bool $authRevoked = false)
    {
        parent::__construct($message);
    }

    /** Discord itself failed (5xx): about the request, not about the grant. */
    public function isServerError(): bool
    {
        return $this->status !== null && $this->status >= 500;
    }
}
