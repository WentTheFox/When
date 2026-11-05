<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A share link no longer has an archive flag of its own: it is archived
     * exactly when its connection is. Links that were archived in their own right
     * (before that, the flag could be set on the link alone) would otherwise come
     * back to life, so archive their connections instead. Runs before the
     * migration that drops share_links.archived, which would lose the information.
     *
     * Idempotent: once a connection is archived there is nothing left to carry.
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE connections SET archived = TRUE
            FROM share_links
            WHERE share_links.connection_id = connections.id
              AND share_links.archived = TRUE
              AND connections.archived = FALSE
        SQL);
    }

    /** Nothing to undo: which connections were archived this way isn't recorded, and archiving is harmless. */
    public function down(): void {}
};
