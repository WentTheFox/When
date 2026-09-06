<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lets an owner pick a per-role icon (e.g. a house for "Visiting", a
     * suitcase for "Hosting") shown on a matched highlighted block instead
     * of the share link's own generic highlighted icon — same App\Support\
     * IconKey catalog as every other *_icon_key column, plain (not
     * encrypted) since it's a curated key, not owner-authored freetext.
     * Nullable and optional: leaving it unset just keeps using the regular
     * highlighted icon, same "blank falls back to the slot's own default"
     * convention every other icon_key column already has.
     */
    public function up(): void
    {
        Schema::table('activity_localizations', function (Blueprint $table) {
            $table->string('icon_key', 20)->nullable()->after('sort_order');
        });
    }

    public function down(): void
    {
        Schema::table('activity_localizations', function (Blueprint $table) {
            $table->dropColumn('icon_key');
        });
    }
};
