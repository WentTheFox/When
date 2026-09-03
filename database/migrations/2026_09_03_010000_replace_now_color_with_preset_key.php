<?php

use App\Support\NowColorPresetKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Supersedes add_now_color_to_users_table's free-form hex column with
     * the same KEY-into-a-curated-palette scheme
     * replace_owner_colors_with_palette_keys already applied to the other
     * six color slots — see App\Support\NowColorPresetKey. An owner-chosen
     * arbitrary hex could read badly against one theme; the whole point of
     * moving to enum-backed presets with their own light/dark pair is that
     * this can no longer happen. Any previously-set custom now_color hex is
     * simply lost (reset to the app default) — a cosmetic-only setting, not
     * worth a value-preserving best-effort hex-to-nearest-preset mapping.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('now_color');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('now_color_key', 20)->nullable();
        });

        $keys = "'".implode("', '", array_column(NowColorPresetKey::cases(), 'value'))."'";

        DB::statement(
            'ALTER TABLE users ADD CONSTRAINT users_now_color_key_check CHECK ('.
            "(now_color_key IS NULL OR now_color_key IN ({$keys}))".
            ')'
        );
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('now_color_key');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('now_color', 7)->nullable();
        });

        DB::statement(
            'ALTER TABLE users ADD CONSTRAINT users_now_color_format_check CHECK ('.
            "(now_color IS NULL OR now_color ~ '^#[0-9a-fA-F]{6}$')".
            ')'
        );
    }
};
