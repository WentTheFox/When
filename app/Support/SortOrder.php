<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;

/**
 * The integer position of a record in one of the Connections page's master
 * lists (connections, sources, categories, custom attributes).
 *
 * The server can't sort these itself (their names are E2EE), so the client
 * decides the order and sends it; this stores it. Lists are read back with
 * ORDER BY sort_order, so they render in a stable order from the first paint.
 */
final class SortOrder
{
    /**
     * Where a new record goes until the client has placed it: the end.
     *
     * @param  Relation<*, *, *>  $ownedRecords
     */
    public static function next(Relation $ownedRecords): int
    {
        $max = $ownedRecords->getQuery()->withoutGlobalScope(SoftDeletingScope::class)->max('sort_order');

        return $max === null ? 0 : ((int) $max) + 1;
    }

    /**
     * Gives the listed records the positions 0, 1, 2… in the order given.
     * Only the user's own live records are touched; unknown or foreign ids are
     * ignored. Returns how many rows were updated.
     *
     * @param  list<string>  $ids
     */
    public static function apply(string $table, string $userId, array $ids): int
    {
        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            return 0;
        }

        $values = implode(', ', array_fill(0, count($ids), '(?::uuid, ?::integer)'));
        $bindings = [];
        foreach ($ids as $position => $id) {
            $bindings[] = $id;
            $bindings[] = $position;
        }

        return DB::update(
            "UPDATE {$table} SET sort_order = v.position FROM (VALUES {$values}) AS v(id, position) "
            ."WHERE {$table}.id = v.id AND {$table}.user_id = ? AND {$table}.deleted_at IS NULL",
            [...$bindings, $userId],
        );
    }
}
