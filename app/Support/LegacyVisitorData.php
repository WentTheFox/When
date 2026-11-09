<?php

namespace App\Support;

use App\Models\ShareLinkVisit;
use App\Models\User;

/**
 * What the server has recorded about the visitors of an owner's share links, grouped per connection, for the
 * one-time copy into the E2EE 'language' and 'timezone' custom fields (see ConnectionController::visitorsField).
 * Both columns are APP_KEY-encrypted (ShareLinkVisit), so the grouping happens here in PHP after decryption.
 * Most frequent first, so the field lists a connection's usual language/zone before the odd one.
 */
final class LegacyVisitorData
{
    /**
     * @return list<array{connection_id: string, languages: list<string>, timezones: list<string>}>
     */
    public static function forUser(User $user): array
    {
        /** @var array<string, array{languages: array<string, int>, timezones: array<string, int>}> $tallies */
        $tallies = [];

        $visits = ShareLinkVisit::query()
            ->join('share_links', 'share_links.id', '=', 'share_link_visits.share_link_id')
            ->where('share_links.user_id', $user->id)
            // Only links that belong to a connection and were ever visited (last_visited_at is backfilled from the visits) can carry data over.
            ->whereNotNull('share_links.connection_id')
            ->whereNotNull('share_links.last_visited_at')
            ->select(['share_link_visits.timezone', 'share_link_visits.locale', 'share_links.connection_id'])
            ->cursor();

        foreach ($visits as $visit) {
            $connectionId = (string) $visit->getAttribute('connection_id');
            $tallies[$connectionId] ??= ['languages' => [], 'timezones' => []];

            $timezone = trim((string) $visit->timezone);
            if ($timezone !== '') {
                $tallies[$connectionId]['timezones'][$timezone] = ($tallies[$connectionId]['timezones'][$timezone] ?? 0) + 1;
            }

            $language = self::languageOf($visit->locale);
            if ($language !== null) {
                $tallies[$connectionId]['languages'][$language] = ($tallies[$connectionId]['languages'][$language] ?? 0) + 1;
            }
        }

        $rank = function (array $counts): array {
            arsort($counts);

            return array_map('strval', array_keys($counts));
        };

        $rows = [];
        foreach ($tallies as $connectionId => $tally) {
            $rows[] = [
                'connection_id' => $connectionId,
                'languages' => $rank($tally['languages']),
                'timezones' => $rank($tally['timezones']),
            ];
        }

        return $rows;
    }

    /** The lowercase primary language subtag of a browser locale ("en-US" becomes "en"), or null when there is none. */
    public static function languageOf(?string $locale): ?string
    {
        if ($locale === null || ! preg_match('/^[A-Za-z]{2,3}(?=$|[-_])/', trim($locale), $match)) {
            return null;
        }

        return strtolower($match[0]);
    }
}
