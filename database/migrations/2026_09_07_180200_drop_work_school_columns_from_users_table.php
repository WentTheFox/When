<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the dedicated work/school pattern-matching settings — superseded
 * by activity_localizations rows tagged category 'work'/'school' (see the
 * two previous migrations: the column this table gained, and the data
 * migration that moved every existing user's values over before this one
 * runs).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'work_event_pattern',
                'work_event_pattern_preview',
                'school_event_pattern',
                'school_event_pattern_preview',
                'work_color_key',
                'work_icon_key',
                'school_color_key',
                'school_icon_key',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('work_event_pattern')->nullable()->after('nap_event_pattern_preview');
            $table->text('work_event_pattern_preview')->nullable()->after('work_event_pattern');
            $table->text('school_event_pattern')->nullable()->after('work_event_pattern_preview');
            $table->text('school_event_pattern_preview')->nullable()->after('school_event_pattern');
            $table->string('work_color_key', 20)->nullable()->after('busy_color_key');
            $table->string('work_icon_key', 20)->nullable()->after('busy_icon_key');
            $table->string('school_color_key', 20)->nullable()->after('work_color_key');
            $table->string('school_icon_key', 20)->nullable()->after('work_icon_key');
        });
    }
};
