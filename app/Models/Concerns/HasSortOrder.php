<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * For the Connections page's master lists: records carry an integer
 * `sort_order` chosen by the client (names are E2EE, so only it can sort them
 * alphabetically). Reading them back with ordered() gives a stable order that
 * doesn't depend on decrypting anything.
 */
trait HasSortOrder
{
    /** @param  Builder<static>  $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy($query->qualifyColumn('sort_order'))
            ->orderBy($query->qualifyColumn('created_at'))
            ->orderBy($query->qualifyColumn('id'));
    }
}
