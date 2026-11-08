<?php

namespace App\Support;

/**
 * Checks profile fields pasted in by the owner (an imported relationships list) the way Discord itself limits
 * them. The owner's paste is trusted as data about their own contacts, but each value still has to look like
 * something Discord could have produced: a field that does not is dropped (null), never stored half-valid.
 */
final class DiscordProfileInput
{
    /** Display names, usernames and friend nicknames are at most 32 characters. */
    public const NAME_MAX = 32;

    /** An avatar hash: 32 hex digits, with an a_ prefix when the avatar is animated. */
    public const AVATAR_PATTERN = '/^(a_)?[0-9a-f]{32}$/';

    /** A display name or nickname: 1 to 32 characters, no control characters. */
    public static function name(mixed $value, int $min = 1): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);
        $length = mb_strlen($value);

        if ($length < $min || $length > self::NAME_MAX || ! mb_check_encoding($value, 'UTF-8') || preg_match('/\p{Cc}/u', $value) === 1) {
            return null;
        }

        return $value;
    }

    /** A username: 2 to 32 characters (newer ones are lowercase letters, digits, "_" and "."; older ones may differ). */
    public static function username(mixed $value): ?string
    {
        return self::name($value, 2);
    }

    public static function avatarHash(mixed $value): ?string
    {
        return is_string($value) && preg_match(self::AVATAR_PATTERN, $value) === 1 ? $value : null;
    }
}
