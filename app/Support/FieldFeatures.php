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
     * on that table that identifies the owning user. `label` is shown to the
     * owner (it must not be derived from the field itself: field names are E2EE).
     *
     * @var array<string, array{table: string, column: string, user_column: string, label: string}>
     */
    public const BINDINGS = [
        'highlight_tokens' => [
            'table' => 'users',
            'column' => 'highlight_identifier_definition_id',
            'user_column' => 'id',
            'label' => 'Highlight tokens',
        ],
        'bypass_dnd' => [
            'table' => 'users',
            'column' => 'bypass_dnd_definition_id',
            'user_column' => 'id',
            'label' => 'Bypass DND',
        ],
        'vrchat_user_id' => [
            'table' => 'users',
            'column' => 'vrchat_user_id_definition_id',
            'user_column' => 'id',
            'label' => 'VRChat user ID',
        ],
        'discord_user_id' => [
            'table' => 'users',
            'column' => 'discord_user_id_definition_id',
            'user_column' => 'id',
            'label' => 'Discord user ID',
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
}
