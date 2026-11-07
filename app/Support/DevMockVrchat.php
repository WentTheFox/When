<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Invented VRChat data for local development: the mock VRCX server (DevMockVrcxController) answers
 * from this, and ConnectionsDemoSeeder links connections to the same ids, so the two always agree.
 *
 * Everything is derived from the user's number, so it is identical on every run and needs no storage.
 * The accounts are fictional: a handle-style display name, a generated avatar, and made-up time
 * together. Never used in production (the mock server is not registered there).
 */
final class DevMockVrchat
{
    /** The API key the mock VRCX server accepts, and the seeder stores. Not a secret. */
    public const API_KEY = 'dev-mock-vrcx-key';

    /** How many mock users VRCX "knows"; the seeder links the first few to connections, the rest are pending friends. */
    public const USER_COUNT = 20;

    private const ADJECTIVES = ['Quiet', 'Mossy', 'Pixel', 'Neon', 'Sleepy', 'Cosmic', 'Tiny', 'Velvet', 'Rusty', 'Lunar', 'Frosty', 'Amber'];

    private const NOUNS = ['Fox', 'Lynx', 'Otter', 'Owl', 'Moth', 'Comet', 'Bean', 'Sprout', 'Kettle', 'Pebble', 'Raven', 'Toad'];

    /** A valid VRChat user id ("usr_" + UUID) for mock user $n (1-based). */
    public static function userId(int $n): string
    {
        $h = md5("when-dev-mock-vrchat-user-$n");

        return 'usr_'.substr($h, 0, 8).'-'.substr($h, 8, 4).'-'.substr($h, 12, 4).'-'.substr($h, 16, 4).'-'.substr($h, 20, 12);
    }

    /** The mock user number behind an id, or null when VRCX "does not know" it. */
    public static function numberOf(string $id): ?int
    {
        for ($n = 1; $n <= self::USER_COUNT; $n++) {
            if (self::userId($n) === $id) {
                return $n;
            }
        }

        return null;
    }

    /**
     * What VRCX's API returns for one user.
     *
     * @return array{displayName: string, profilePictureUrl: string, avatarThumbnailUrl: string, discordId: ?string, note: ?string}
     */
    public static function profile(int $n): array
    {
        $name = self::ADJECTIVES[$n % count(self::ADJECTIVES)].self::NOUNS[intdiv($n * 7, 2) % count(self::NOUNS)].($n > 12 ? (string) ($n * 3) : '');
        $avatar = rtrim((string) config('app.url'), '/')."/dev/mock-vrcx/avatar/$n.svg";

        return [
            'displayName' => $name,
            'profilePictureUrl' => $avatar,
            'avatarThumbnailUrl' => $avatar,
            'discordId' => self::discordId($n),
            // The owner's private VRChat note; only a couple of users have one, so the "carry over" prompt is demoable.
            'note' => in_array($n, [2, 5], true) ? "Met in a world instance; swaps avatars a lot (mock note $n)." : null,
        ];
    }

    /** A Discord snowflake for every third mock user (VRChat only shows one when the user linked it). */
    public static function discordId(int $n): ?string
    {
        return $n % 3 === 0 ? (string) (400000000000000000 + $n * 7919 * 1000003) : null;
    }

    /**
     * Shared-instance intervals with this user in the last $days days, oldest first. Anchored to the start of
     * the current week so repeated syncs within a week return identical intervals (the sync de-duplicates on start time).
     *
     * @return list<array{start: string, end: string, durationMs: int, otherPlayerCount: int}>
     */
    public static function intervals(int $n, int $days): array
    {
        $anchor = CarbonImmutable::now('UTC')->startOfWeek()->startOfDay();
        $bytes = unpack('C*', hash('sha256', "when-dev-mock-stays-$n", true)) ?: [];
        $count = 3 + ($bytes[1] % 6);
        $cutoff = CarbonImmutable::now('UTC')->subDays($days);

        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $start = $anchor->subDays(1 + ($bytes[2 + $i] % 85))->addHours(17 + ($bytes[8 + $i] % 5))->addMinutes(($bytes[4 + $i] % 4) * 15);
            $minutes = 20 + ($bytes[10 + $i] % 160);

            if ($start->lt($cutoff)) {
                continue;
            }

            $rows[] = [
                'start' => $start->format('Y-m-d\TH:i:s\Z'),
                'end' => $start->addMinutes($minutes)->format('Y-m-d\TH:i:s\Z'),
                'durationMs' => $minutes * 60 * 1000,
                // Some instances are one-on-one (nobody else there).
                'otherPlayerCount' => $bytes[12 + $i] % 3 === 0 ? 0 : 1 + ($bytes[12 + $i] % 11),
            ];
        }

        usort($rows, fn (array $a, array $b) => strcmp($a['start'], $b['start']));

        return $rows;
    }

    /** A round avatar with the user's initial, in a colour taken from the number. */
    public static function avatarSvg(int $n): string
    {
        $initial = substr(self::profile($n)['displayName'], 0, 1);
        $hue = ($n * 47) % 360;

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="64" height="64">'
            ."<rect width=\"64\" height=\"64\" fill=\"hsl($hue 55% 45%)\"/>"
            ."<text x=\"32\" y=\"43\" font-family=\"sans-serif\" font-size=\"32\" font-weight=\"700\" text-anchor=\"middle\" fill=\"#fff\">$initial</text>"
            .'</svg>';
    }
}
