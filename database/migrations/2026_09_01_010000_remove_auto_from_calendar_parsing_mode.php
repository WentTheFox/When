<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * 'auto' relied on a per-event heuristic (FeedClassifier::isGeneric())
     * re-run on every fetch to decide title-processing per event, which
     * left DND/nap and tentative-suffix title matching un-gated (only
     * HighlightMatcher ever checked the resulting flag) — a feed that
     * looks full_detail (real VEVENTs) but redacts every SUMMARY to a
     * generic placeholder like "Busy" made that inconsistency concrete.
     * Collapsing to a strict binary lets the stored mode gate every
     * title-processing path directly, with no per-event guessing left
     * anywhere. Existing 'auto' rows move to 'full_detail' — the same
     * "don't guess, assume real content" default FeedClassifier::classify()
     * itself already falls back to when it has no signal.
     */
    public function up(): void
    {
        DB::statement("UPDATE users SET calendar_parsing_mode = 'full_detail' WHERE calendar_parsing_mode = 'auto'");

        DB::statement('ALTER TABLE users ALTER COLUMN calendar_parsing_mode SET DEFAULT \'full_detail\'');

        DB::statement('ALTER TABLE users DROP CONSTRAINT users_calendar_parsing_mode_check');
        DB::statement(
            'ALTER TABLE users ADD CONSTRAINT users_calendar_parsing_mode_check '.
            "CHECK (calendar_parsing_mode IN ('full_detail', 'free_busy_only'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_calendar_parsing_mode_check');
        DB::statement(
            'ALTER TABLE users ADD CONSTRAINT users_calendar_parsing_mode_check '.
            "CHECK (calendar_parsing_mode IN ('full_detail', 'free_busy_only', 'auto'))"
        );

        DB::statement('ALTER TABLE users ALTER COLUMN calendar_parsing_mode SET DEFAULT \'auto\'');
    }
};
