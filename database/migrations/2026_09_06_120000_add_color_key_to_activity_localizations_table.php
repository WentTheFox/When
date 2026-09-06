<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lets an owner pick a per-role color (e.g. a distinct swatch for
     * "Visiting" vs "Hosting") shown on a matched highlighted block instead
     * of the share link's own flat highlight_color_key — same App\Support\
     * ColorSwatchKey catalog as every other *_color_key column, plain (not
     * encrypted) since it's a curated key, not owner-authored freetext.
     * Nullable and optional, same convention as icon_key added above:
     * leaving it unset just keeps using the regular highlighted color.
     */
    public function up(): void
    {
        Schema::table('activity_localizations', function (Blueprint $table) {
            $table->string('color_key', 20)->nullable()->after('icon_key');
        });
    }

    public function down(): void
    {
        Schema::table('activity_localizations', function (Blueprint $table) {
            $table->dropColumn('color_key');
        });
    }
};
