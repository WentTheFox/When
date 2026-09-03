<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The current-time indicator (the line/dot marking "now" on the week
     * and month views) was a hardcoded #e5566a — same customization story
     * as the other public-page colors (see
     * add_public_page_colors_to_users_table). Null means "use the app
     * default," same convention as those.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('now_color', 7)->nullable()->after('highlight_color');
        });

        DB::statement(
            'ALTER TABLE users ADD CONSTRAINT users_now_color_format_check CHECK ('.
            "(now_color IS NULL OR now_color ~ '^#[0-9a-fA-F]{6}$')".
            ')'
        );
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('now_color');
        });
    }
};
