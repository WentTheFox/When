<?php

use App\Support\ColorPalette;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Supersedes add_public_page_colors_to_users_table's free-form hex
     * columns: an owner picking any arbitrary hex meant a color chosen
     * while previewing one theme often read badly on the other. Each of
     * these six slots now stores a KEY into the app's own curated palette
     * (see App\Support\ColorPalette / resources/js/free/color-palette.ts),
     * which already has a hand-picked light AND dark hex per entry — no
     * more storing a hex here at all. now_color is untouched: it was
     * always theme-independent, never part of this problem.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'accent_color',
                'secondary_color',
                'sleep_color',
                'busy_color',
                'free_color',
                'highlight_color',
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('accent_color_key', 20)->nullable();
            $table->string('secondary_color_key', 20)->nullable();
            $table->string('sleep_color_key', 20)->nullable();
            $table->string('busy_color_key', 20)->nullable();
            $table->string('free_color_key', 20)->nullable();
            $table->string('highlight_color_key', 20)->nullable();
        });

        $keys = "'".implode("', '", ColorPalette::KEYS)."'";

        DB::statement(
            'ALTER TABLE users ADD CONSTRAINT users_color_keys_check CHECK ('.
            "(accent_color_key IS NULL OR accent_color_key IN ({$keys})) AND ".
            "(secondary_color_key IS NULL OR secondary_color_key IN ({$keys})) AND ".
            "(sleep_color_key IS NULL OR sleep_color_key IN ({$keys})) AND ".
            "(busy_color_key IS NULL OR busy_color_key IN ({$keys})) AND ".
            "(free_color_key IS NULL OR free_color_key IN ({$keys})) AND ".
            "(highlight_color_key IS NULL OR highlight_color_key IN ({$keys}))".
            ')'
        );
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'accent_color_key',
                'secondary_color_key',
                'sleep_color_key',
                'busy_color_key',
                'free_color_key',
                'highlight_color_key',
            ]);

            $table->string('accent_color', 7)->nullable();
            $table->string('secondary_color', 7)->nullable();
            $table->string('sleep_color', 7)->nullable();
            $table->string('busy_color', 7)->nullable();
            $table->string('free_color', 7)->nullable();
            $table->string('highlight_color', 7)->nullable();
        });

        DB::statement(
            'ALTER TABLE users ADD CONSTRAINT users_public_page_colors_format_check CHECK ('.
            "(accent_color IS NULL OR accent_color ~ '^#[0-9a-fA-F]{6}$') AND ".
            "(secondary_color IS NULL OR secondary_color ~ '^#[0-9a-fA-F]{6}$') AND ".
            "(sleep_color IS NULL OR sleep_color ~ '^#[0-9a-fA-F]{6}$') AND ".
            "(busy_color IS NULL OR busy_color ~ '^#[0-9a-fA-F]{6}$') AND ".
            "(free_color IS NULL OR free_color ~ '^#[0-9a-fA-F]{6}$') AND ".
            "(highlight_color IS NULL OR highlight_color ~ '^#[0-9a-fA-F]{6}$')".
            ')'
        );
    }
};
