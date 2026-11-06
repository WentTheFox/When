<?php

namespace App\Support;

/**
 * Discord user ids (snowflakes: 17-20 digits), as stored in an identifier /
 * identifier_list custom field. hash() is the keyed stand-in used wherever the
 * (encrypted) id has to be looked up or kept unique.
 */
final class DiscordUserId
{
    public const PATTERN = '/^\d{17,20}$/';

    /** The canonical (trimmed) id, or null when the text isn't a Discord user id. */
    public static function normalize(?string $value): ?string
    {
        $id = trim((string) $value);

        return preg_match(self::PATTERN, $id) === 1 ? $id : null;
    }

    public static function hash(string $id): string
    {
        return hash_hmac('sha256', 'discord:'.$id, (string) config('app.key'));
    }
}
