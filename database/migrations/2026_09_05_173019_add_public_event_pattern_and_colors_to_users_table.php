<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Seventh calendar block category ("public events"), same three-column
     * shape as work/school (add_work_event_name_to_users_table/
     * add_work_color_key_to_users_table/add_school_category_to_users_table):
     * an event-name pattern plus a color/icon key pair. `public_event_pattern`
     * and its `_preview` sibling are created straight as `text`, encrypted
     * from day one (§0.2 Crypt/APP_KEY tier, same as every other *_pattern
     * column since 2026_09_03_120000_encrypt_pattern_and_timezone_fields) —
     * no plaintext-then-migrate step needed since this column has never held
     * data. No CHECK constraint on the color/icon key columns — see
     * add_school_category_to_users_table's own doc comment: the PHP enum
     * (ColorSwatchKey/IconKey) is the only source of truth now.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('public_event_pattern')->nullable()->after('school_event_pattern');
            $table->text('public_event_pattern_preview')->nullable()->after('public_event_pattern');
            $table->string('public_color_key', 20)->nullable()->after('school_color_key');
            $table->string('public_icon_key', 20)->nullable()->after('school_icon_key');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['public_event_pattern', 'public_event_pattern_preview', 'public_color_key', 'public_icon_key']);
        });
    }
};
