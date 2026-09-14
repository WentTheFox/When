<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A role's pattern capture group is now optional (see
     * App\Support\Regex::validateAtMostOneCaptureGroup) — a pattern with no
     * capture group (e.g. `^gaming`) now just gates *whether* a role
     * applies, and HighlightMatcher::matchClauseText falls back to the
     * owner's default/custom highlight clause pattern to actually extract
     * the highlighted name(s), instead of forcing every role to duplicate
     * that "with X, Y, Z" capturing itself. Computed once at validation
     * time (ActivityLocalizationController) rather than re-counting the
     * pattern's capture groups on every match, since match runs on every
     * cached-availability recompute for every viewer.
     */
    public function up(): void
    {
        Schema::table('activity_localizations', function (Blueprint $table) {
            $table->boolean('has_capture_group')->default(false)->after('pattern_preview');
        });
    }

    public function down(): void
    {
        Schema::table('activity_localizations', function (Blueprint $table) {
            $table->dropColumn('has_capture_group');
        });
    }
};
