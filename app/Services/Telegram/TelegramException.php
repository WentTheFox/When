<?php

namespace App\Services\Telegram;

/**
 * A Telegram sidecar call failed. The message is safe to show the owner: it never contains a phone number, a
 * login code, a password or the session string. `authRevoked` means the session is gone for good (logged out
 * from another device, account banned): the owner must log in again. `retryAfter` is Telegram's flood wait in seconds.
 */
class TelegramException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly bool $authRevoked = false,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }
}
