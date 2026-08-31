<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Customizable "activity" prefix pattern — the freetext before "with"/
     * "w/" in an event title (e.g. "Dinner" in "Dinner with Alice"),
     * independent of highlight_clause_pattern (which extracts the name/
     * token, not the activity). Null means "use the built-in default."
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('activity_clause_pattern')->nullable()->after('highlight_clause_pattern');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('activity_clause_pattern');
        });
    }
};
