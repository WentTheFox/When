<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Flips the share-link <-> connection relation: a share link now belongs to
     * exactly one connection (share_links.connection_id, NOT NULL + UNIQUE,
     * cascade on delete) instead of connections optionally pointing at a link.
     * The link's own label goes away — its name is the connection's name.
     *
     * Existing links with no connection can't be migrated (their label is
     * E2EE, so the server can't turn it into a connection name) and anonymous
     * links no longer exist as a concept, so they are deleted here.
     *
     * Expand-only for the zero-downtime flow: connections.share_link_id and
     * share_links.label_ciphertext are left in place (now unused) and dropped
     * by a later migration once this release is the only one running.
     */
    public function up(): void
    {
        Schema::table('share_links', function (Blueprint $table) {
            $table->foreignUuid('connection_id')->nullable()->after('user_id')
                ->constrained('connections')->cascadeOnDelete();
        });

        // Backfill from the old direction. If several connections pointed at
        // one link, the oldest wins (the old model resolved "first" too).
        DB::statement(<<<'SQL'
            UPDATE share_links SET connection_id = (
                SELECT c.id FROM connections c
                WHERE c.share_link_id = share_links.id
                ORDER BY c.created_at ASC, c.id ASC
                LIMIT 1
            )
        SQL);

        // Orphans (and their dependent rows) go.
        $orphans = DB::table('share_links')->whereNull('connection_id')->pluck('id');
        foreach ($orphans->chunk(500) as $chunk) {
            DB::table('share_link_words')->whereIn('share_link_id', $chunk)->delete();
            DB::table('share_link_cache')->whereIn('share_link_id', $chunk)->delete();
            DB::table('share_link_visits')->whereIn('share_link_id', $chunk)->delete();
            DB::table('share_links')->whereIn('id', $chunk)->delete();
        }

        Schema::table('share_links', function (Blueprint $table) {
            $table->uuid('connection_id')->nullable(false)->change();
            $table->unique('connection_id');
        });
    }

    public function down(): void
    {
        Schema::table('share_links', function (Blueprint $table) {
            $table->dropConstrainedForeignId('connection_id');
        });
    }
};
