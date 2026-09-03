<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Same reasoning as drop_stale_enum_check_constraints — now that
     * calendar_parsing_mode is validated with Rule::enum(App\Support\
     * CalendarParsingMode::class) (SettingsController/
     * CalendarPreviewController), the DB-level CHECK is a second,
     * separately-maintained copy of the same two-value list rather than a
     * safety net against anything the app layer doesn't already catch.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_calendar_parsing_mode_check');
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE users ADD CONSTRAINT users_calendar_parsing_mode_check CHECK ('.
            "(calendar_parsing_mode)::text = ANY (ARRAY['full_detail'::character varying, 'free_busy_only'::character varying]::text[])".
            ')'
        );
    }
};
