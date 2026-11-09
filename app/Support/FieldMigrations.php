<?php

namespace App\Support;

/**
 * Every in-place custom field type change the owner may make ("Migrate field" in the attributes panel).
 * One registry feeds both the endpoint (ConnectionAttributeDefinitionController::migrate) and the UI
 * (shared as the `fieldMigrations` page prop), so the two can't disagree about what's allowed.
 *
 * `tier` is what happens to the encryption of the values:
 *   e2ee      E2EE -> E2EE: the browser decrypts, converts and re-encrypts (value_ciphertext).
 *   to_plain  E2EE -> plaintext column (§0.2 tier): the browser decrypts and sends the plaintext. A privacy downgrade.
 *   to_e2ee   plaintext column -> E2EE: the browser encrypts what it holds. A privacy upgrade.
 *   plain     plaintext -> plaintext: the server does it alone, nothing is sent.
 *
 * `lossy` is true when some values can come out different from how they went in. The FIELD id, label, position,
 * icon rules (re-filtered by the browser) and every feature binding the new type still supports are kept.
 */
final class FieldMigrations
{
    public const TIER_E2EE = 'e2ee';

    public const TIER_TO_PLAIN = 'to_plain';

    public const TIER_TO_E2EE = 'to_e2ee';

    public const TIER_PLAIN = 'plain';

    /** The columns on connection_attribute_values and the types that live in each. */
    public const COLUMN_OF_TYPE = [
        'identifier' => 'identifier_value',
        'identifier_list' => 'identifier_list_value',
        'language' => 'identifier_list_value',
        'timezone' => 'identifier_list_value',
        'boolean' => 'boolean_value',
    ];

    /**
     * @return list<array{from: string, to: string, tier: string, lossy: bool, strategies?: list<string>}>
     */
    public static function all(): array
    {
        $paths = [];

        foreach (['email', 'phone', 'url', 'number', 'date', 'country', 'radio'] as $from) {
            $paths[] = self::path($from, 'text', self::TIER_E2EE);
        }
        foreach (['textarea', 'email', 'phone', 'url'] as $to) {
            $paths[] = self::path('text', $to, self::TIER_E2EE);
        }
        $paths[] = self::path('textarea', 'text', self::TIER_E2EE, true);
        $paths[] = self::path('radio', 'textarea', self::TIER_E2EE);
        $paths[] = self::path('radio', 'list', self::TIER_E2EE);
        $paths[] = self::path('text', 'list', self::TIER_E2EE, true);
        $paths[] = self::path('textarea', 'list', self::TIER_E2EE, true);
        $paths[] = self::path('list', 'textarea', self::TIER_E2EE);
        $paths[] = self::path('text', 'identifier', self::TIER_TO_PLAIN);
        $paths[] = self::path('list', 'identifier_list', self::TIER_TO_PLAIN);
        $paths[] = self::path('identifier', 'identifier_list', self::TIER_PLAIN);
        $paths[] = self::path('identifier_list', 'identifier', self::TIER_PLAIN, true, ['first', 'join']);
        $paths[] = self::path('identifier_list', 'list', self::TIER_TO_E2EE);
        $paths[] = self::path('boolean', 'radio', self::TIER_TO_E2EE);

        return $paths;
    }

    /**
     * @param  list<string>|null  $strategies
     * @return array{from: string, to: string, tier: string, lossy: bool, strategies?: list<string>}
     */
    private static function path(string $from, string $to, string $tier, bool $lossy = false, ?array $strategies = null): array
    {
        $path = ['from' => $from, 'to' => $to, 'tier' => $tier, 'lossy' => $lossy];

        return $strategies === null ? $path : $path + ['strategies' => $strategies];
    }

    /** @return array{from: string, to: string, tier: string, lossy: bool, strategies?: list<string>}|null */
    public static function find(string $from, string $to): ?array
    {
        foreach (self::all() as $path) {
            if ($path['from'] === $from && $path['to'] === $to) {
                return $path;
            }
        }

        return null;
    }
}
