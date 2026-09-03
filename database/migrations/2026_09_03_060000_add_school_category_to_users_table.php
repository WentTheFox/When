<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sixth calendar block category, same three-column shape as work
     * (add_work_event_name_to_users_table/add_work_color_key_to_users_
     * table/add_icon_keys_to_users_table's work_icon_key): an event-name
     * pattern (blank = genuinely off, same non-functional-fallback
     * caveat as dnd/nap/work — see SettingsController's own doc comment)
     * plus a color/icon key pair. No CHECK constraint on either key
     * column — see drop_stale_enum_check_constraints's own doc comment
     * for why the PHP enum (ColorSwatchKey/IconKey) is the only source of
     * truth now, not a DB-level copy of it.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('school_event_pattern')->nullable()->after('work_event_pattern');
            $table->string('school_color_key', 20)->nullable()->after('work_color_key');
            $table->string('school_icon_key', 20)->nullable()->after('work_icon_key');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['school_event_pattern', 'school_color_key', 'school_icon_key']);
        });
    }
};
