<?php

namespace App\Support;

use App\Models\ConnectionAttributeDefinition;
use App\Models\ConnectionAttributeValue;
use App\Models\ShareLink;
use App\Models\User;
use App\Services\Calendar\HighlightWordsResolver;
use Illuminate\Support\Facades\DB;

/**
 * Copies the languages and time zones that an owner's share links recorded from their visitors onto the owner's
 * connections, as values of a 'language' and a 'timezone' custom field (created here when the owner has none from
 * an earlier run). Both types are §0.2 tier: plaintext lists in identifier_list_value, APP_KEY-encrypted at rest,
 * readable by the server on purpose (they are meant to move from share links to connections entirely), so the server
 * can do this by itself.
 *
 * The one thing it cannot do is name the new fields: a field's label is E2EE, encrypted under a per-record key
 * held in the owner's key ring. They are created with a plaintext `staged_label` ("Visitor languages" / "Visitor
 * time zones", fixed generic names) and a null label_ciphertext; the browser encrypts the staged label and writes it
 * back the next time it reads the field (Connections page), which clears the staged one.
 *
 * The recorded visits stay as they are: the visit log and the owner preview's picker still read them. Safe to run
 * again: entries are merged into what a connection already has, and a field of a type that is still staged is reused.
 */
final class VisitorFieldsBackfill
{
    /** Custom field type => [the LegacyVisitorData key holding its entries, the staged label of the field made for it]. */
    private const KINDS = [
        'language' => ['languages', 'Visitor languages'],
        'timezone' => ['timezones', 'Visitor time zones'],
    ];

    /** Every owner with a visited share link on a connection. */
    public static function forAllUsers(): void
    {
        User::query()
            ->whereIn('id', ShareLink::query()->whereNotNull('connection_id')->whereNotNull('last_visited_at')->select('user_id'))
            ->each(fn (User $user) => self::forUser($user));
    }

    public static function forUser(User $user): void
    {
        $rows = LegacyVisitorData::forUser($user);

        DB::transaction(function () use ($user, $rows) {
            foreach (self::KINDS as $type => [$key, $stagedLabel]) {
                $withData = array_values(array_filter($rows, fn (array $row) => $row[$key] !== []));
                if ($withData === []) {
                    continue;
                }

                $definition = ConnectionAttributeDefinition::where('user_id', $user->id)->where('type', $type)->whereNull('label_ciphertext')->whereNotNull('staged_label')->first()
                    ?? $user->connectionAttributeDefinitions()->create([
                        'label_ciphertext' => null,
                        'staged_label' => $stagedLabel,
                        'type' => $type,
                        'sort_order' => SortOrder::next($user->connectionAttributeDefinitions()),
                    ]);

                foreach ($withData as $row) {
                    $existing = ConnectionAttributeValue::where('connection_id', $row['connection_id'])->where('attribute_definition_id', $definition->id)->first();
                    $merged = HighlightWordsResolver::normalize([...(array) $existing?->identifier_list_value, ...$row[$key]]);

                    ConnectionAttributeValue::updateOrCreate(
                        ['connection_id' => $row['connection_id'], 'attribute_definition_id' => $definition->id],
                        ['value_ciphertext' => null, 'identifier_value' => null, 'identifier_list_value' => $merged, 'boolean_value' => null],
                    );
                }
            }
        });
    }
}
