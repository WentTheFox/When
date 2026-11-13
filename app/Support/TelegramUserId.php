<?php

namespace App\Support;

/**
 * Telegram user ids (positive integers, up to 16 digits), as stored in an identifier / identifier_list
 * custom field. hash() is the keyed stand-in used wherever the (encrypted) id has to be looked up or kept unique.
 */
final class TelegramUserId
{
    public const PATTERN = '/^[1-9]\d{0,15}$/';

    /** The canonical (trimmed) id, or null when the text isn't a Telegram user id. */
    public static function normalize(?string $value): ?string
    {
        $id = trim((string) $value);

        return preg_match(self::PATTERN, $id) === 1 ? $id : null;
    }

    public static function hash(string $id): string
    {
        return hash_hmac('sha256', 'telegram:'.$id, (string) config('app.key'));
    }
}
