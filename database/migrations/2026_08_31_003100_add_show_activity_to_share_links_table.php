<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-link privacy toggle: whether this link's viewers see the
     * extracted "activity" freetext (e.g. "Dinner" in "Dinner with Alice")
     * on a matched highlighted block, on top of just the highlight word
     * itself. Defaults true — the owner already previews exactly what a
     * viewer would see (§5.2) before a link goes live, so opt-out rather
     * than opt-in.
     */
    public function up(): void
    {
        Schema::table('share_links', function (Blueprint $table) {
            $table->boolean('show_activity')->default(true)->after('bypass_dnd');
        });
    }

    public function down(): void
    {
        Schema::table('share_links', function (Blueprint $table) {
            $table->dropColumn('show_activity');
        });
    }
};
