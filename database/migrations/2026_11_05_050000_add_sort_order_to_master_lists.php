<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The Connections page's four master lists. */
    private const TABLES = ['connections', 'connection_sources', 'connection_source_categories', 'connection_attribute_definitions'];

    /**
     * Each master-list record gets a plain integer position, per owner. Names
     * are E2EE, so the server can't order by them: the client decides the
     * order (alphabetical, from the decrypted names) and saves it, and the page
     * renders the records in this order from the first paint, so a name
     * finishing its decryption never has to re-sort the list.
     *
     * Existing rows start in creation order; the client sorts each list
     * alphabetically once after the first unlock and saves that.
     *
     * Additive only, so it is safe to deploy while the previous release runs.
     */
    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->unsignedInteger('sort_order')->default(0);
            });

            DB::statement(<<<SQL
                UPDATE {$table} AS t SET sort_order = r.position
                FROM (
                    SELECT id, ROW_NUMBER() OVER (PARTITION BY user_id ORDER BY created_at, id) - 1 AS position
                    FROM {$table}
                ) AS r
                WHERE t.id = r.id
            SQL);

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->index(['user_id', 'sort_order']);
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropIndex(['user_id', 'sort_order']);
                $blueprint->dropColumn('sort_order');
            });
        }
    }
};
