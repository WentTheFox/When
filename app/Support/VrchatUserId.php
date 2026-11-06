<?php

namespace App\Support;

/**
 * VRChat user ids ("usr_" + a UUID), as stored in an identifier / identifier_list
 * custom field. hash() is the keyed stand-in used wherever the (encrypted) id
 * has to be looked up or kept unique.
 */
final class VrchatUserId
{
    public const PATTERN = '/^usr_[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$/';

    /** The canonical (trimmed, lowercase) id, or null when the text isn't a VRChat user id. */
    public static function normalize(?string $value): ?string
    {
        $id = strtolower(trim((string) $value));

        return preg_match(self::PATTERN, $id) === 1 ? $id : null;
    }

    public static function hash(string $id): string
    {
        return hash_hmac('sha256', $id, (string) config('app.key'));
    }
}
