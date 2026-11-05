<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Contract step for "Archive a share link only through its connection".
     * Deploy only once that release is live everywhere: a link has no archive
     * flag of its own any more (ShareLink::isArchived() reads its connection's).
     */
    public function up(): void
    {
        Schema::table('share_links', function (Blueprint $table) {
            $table->dropColumn('archived');
        });
    }

    /** Restores the column, filled in from each link's connection. */
    public function down(): void
    {
        Schema::table('share_links', function (Blueprint $table) {
            $table->boolean('archived')->default(false);
        });

        DB::statement('UPDATE share_links SET archived = c.archived FROM connections c WHERE c.id = share_links.connection_id');
    }
};
