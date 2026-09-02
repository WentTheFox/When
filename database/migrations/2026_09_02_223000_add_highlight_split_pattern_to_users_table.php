<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The delimiter HighlightMatcher::matchTokens splits a matched "with
     * X, Y, Z" clause's captured text on, before checking each name
     * individually — previously hardcoded to a literal comma. Nullable,
     * same functional-fallback convention as highlight_clause_pattern
     * itself: blank means "use HighlightMatcher::DEFAULT_SPLIT_PATTERN",
     * not "off" (there's nothing to turn off here).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('highlight_split_pattern')->nullable()->after('highlight_clause_pattern');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('highlight_split_pattern');
        });
    }
};
