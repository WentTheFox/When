<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-owner public share-view color customization (Stage 6/7). Applies
     * to every one of the owner's share links — not per-link. Null means
     * "use the app default" for that slot; the default palette itself lives
     * in the frontend, not duplicated into every row here.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
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

    public function down(): void
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
    }
};
