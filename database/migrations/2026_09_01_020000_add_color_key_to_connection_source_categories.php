<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Palette key only (validated against App\Support\ColorSwatchKey by
     * ConnectionSourceCategoryController), same pattern as every other
     * owner-selectable color in this app — never a raw hex. Powers the
     * dashboard connections-graph widget's source node coloring.
     */
    public function up(): void
    {
        Schema::table('connection_source_categories', function (Blueprint $table) {
            $table->string('color_key')->nullable()->after('name_ciphertext');
        });
    }

    public function down(): void
    {
        Schema::table('connection_source_categories', function (Blueprint $table) {
            $table->dropColumn('color_key');
        });
    }
};
