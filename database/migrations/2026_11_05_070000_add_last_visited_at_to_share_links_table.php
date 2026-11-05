<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Null means no visit was ever recorded. Backfilled from existing visits. */
    public function up(): void
    {
        Schema::table('share_links', function (Blueprint $table) {
            $table->timestampTz('last_visited_at')->nullable();
        });

        DB::statement('UPDATE share_links SET last_visited_at = v.latest FROM (SELECT share_link_id, MAX(visited_at) AS latest FROM share_link_visits GROUP BY share_link_id) v WHERE v.share_link_id = share_links.id');
    }

    public function down(): void
    {
        Schema::table('share_links', function (Blueprint $table) {
            $table->dropColumn('last_visited_at');
        });
    }
};
