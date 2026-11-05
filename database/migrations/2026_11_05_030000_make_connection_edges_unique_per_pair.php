<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A "knows" relationship is undirected, so two connections can have at
     * most one edge between them regardless of which side it was created
     * from. The old (from, to) unique index only caught exact repeats, so
     * mirrored A->B / B->A pairs piled up.
     *
     * Existing mirrored pairs are collapsed first, keeping the oldest edge of
     * each pair, then a unique index on the unordered pair enforces it. Soft-
     * deleted rows (account deletion in progress) are ignored by both.
     *
     * Expand-only: label_ciphertext stays in place (unused) and is dropped by
     * a later migration.
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            DELETE FROM connection_edges e
            USING connection_edges o
            WHERE e.id <> o.id
              AND e.deleted_at IS NULL AND o.deleted_at IS NULL
              AND LEAST(e.from_connection_id, e.to_connection_id) = LEAST(o.from_connection_id, o.to_connection_id)
              AND GREATEST(e.from_connection_id, e.to_connection_id) = GREATEST(o.from_connection_id, o.to_connection_id)
              AND (e.created_at, e.id) > (o.created_at, o.id)
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX connection_edges_pair_unique
            ON connection_edges (LEAST(from_connection_id, to_connection_id), GREATEST(from_connection_id, to_connection_id))
            WHERE deleted_at IS NULL
        SQL);
    }

    /** The collapsed duplicates are not restored. */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS connection_edges_pair_unique');
    }
};
