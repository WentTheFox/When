<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Registry of site features that are bound to one of the owner's custom
 * attribute fields (e.g. "which field supplies a share link's highlight
 * tokens"). A field a feature depends on must not be deleted out from under
 * it, and every deletion path asks this class, so a feature only has to be
 * registered here to be protected.
 *
 * Adding a feature that points at a field? Add its column to BINDINGS.
 * FieldFeaturesTest fails if any table gains a foreign key to
 * connection_attribute_definitions that is neither listed here nor explicitly
 * allowed there, and it exercises every entry's protection automatically.
 */
final class FieldFeatures
{
    /**
     * `table`.`column` holds the bound field's id. `user_column` is the column
     * on that table that identifies the owning user. `types` are the field types the feature can use: validation of
     * the binding and the field-migration check (see bindingsBrokenBy) both read it, so it lives only here. `label` is shown to the
     * owner (it must not be derived from the field itself: field names are E2EE). `pivot` marks a table with one row
     * per bound field: releasing deletes the row instead of nulling the column.
     *
     * @var array<string, array{table: string, column: string, types: list<string>, user_column: string, label: string, pivot?: bool}>
     */
    public const BINDINGS = [
        'highlight_tokens' => [
            'table' => 'users',
            'column' => 'highlight_identifier_definition_id',
            'types' => ['identifier_list'],
            'user_column' => 'id',
            'label' => 'Highlight tokens',
        ],
        'bypass_dnd' => [
            'table' => 'users',
            'column' => 'bypass_dnd_definition_id',
            'types' => ['boolean'],
            'user_column' => 'id',
            'label' => 'Bypass DND',
        ],
        'alternative_names' => [
            'table' => 'users',
            'column' => 'alternative_names_definition_id',
            'types' => ['identifier_list', 'list', 'textarea'],
            'user_column' => 'id',
            'label' => 'Alternative names',
        ],
        'vrchat_user_id' => [
            'table' => 'users',
            'column' => 'vrchat_user_id_definition_id',
            'types' => ['identifier', 'identifier_list'],
            'user_column' => 'id',
            'label' => 'VRChat user ID',
        ],
        'discord_user_id' => [
            'table' => 'users',
            'column' => 'discord_user_id_definition_id',
            'types' => ['identifier', 'identifier_list'],
            'user_column' => 'id',
            'label' => 'Discord user ID',
        ],
        // Several fields may feed the widget, so these are rows of a pivot table, not one column on users.
        'network_graph' => [
            'table' => 'connection_graph_fields',
            'column' => 'definition_id',
            'types' => ['relationship', 'connection'],
            'user_column' => 'user_id',
            'label' => 'Network graph',
            'pivot' => true,
        ],
    ];

    /**
     * Every field of this user that a feature currently depends on.
     *
     * @return array<string, list<string>> field id => labels of the features using it
     */
    public static function forUser(User $user): array
    {
        $map = [];

        foreach (self::BINDINGS as $binding) {
            $ids = DB::table($binding['table'])
                ->where($binding['user_column'], $user->getKey())
                ->whereNotNull($binding['column'])
                ->pluck($binding['column']);

            foreach ($ids as $id) {
                $map[$id][] = $binding['label'];
            }
        }

        return $map;
    }

    /** @return list<string> Empty when nothing depends on the field, i.e. it may be deleted. */
    public static function labelsFor(User $user, string $definitionId): array
    {
        return self::forUser($user)[$definitionId] ?? [];
    }

    /**
     * The bindings of this field that its becoming `$newType` would invalidate.
     *
     * @return array<string, string> binding key => label
     */
    public static function bindingsBrokenBy(User $user, string $definitionId, string $newType): array
    {
        $broken = [];

        foreach (self::BINDINGS as $key => $binding) {
            $bound = DB::table($binding['table'])
                ->where($binding['user_column'], $user->getKey())
                ->where($binding['column'], $definitionId)
                ->exists();

            if ($bound && ! in_array($newType, $binding['types'], true)) {
                $broken[$key] = $binding['label'];
            }
        }

        return $broken;
    }

    /**
     * Unbinds the field from the given bindings.
     *
     * @param  list<string>  $keys  keys from bindingsBrokenBy
     */
    public static function release(User $user, string $definitionId, array $keys): void
    {
        foreach ($keys as $key) {
            $binding = self::BINDINGS[$key];
            $query = DB::table($binding['table'])
                ->where($binding['user_column'], $user->getKey())
                ->where($binding['column'], $definitionId);

            ($binding['pivot'] ?? false) ? $query->delete() : $query->update([$binding['column'] => null]);
        }
    }
}
