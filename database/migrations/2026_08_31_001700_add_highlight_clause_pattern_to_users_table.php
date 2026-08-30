<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Customizable "with X" / "w/ X" activity-clause pattern (§5.1). A
     * regex fragment (no delimiters), same convention as DND/nap event-name
     * matching. Null means "use the built-in with/w-slash default."
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('highlight_clause_pattern')->nullable()->after('calendar_parsing_mode');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('highlight_clause_pattern');
        });
    }
};
