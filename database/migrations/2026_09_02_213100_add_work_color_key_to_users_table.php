<?php

use App\Support\ColorSwatchKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Seventh palette slot, same KEY-into-ColorPalette scheme as the six
     * added by replace_owner_colors_with_palette_keys — the dashboard
     * time-breakdown widget's "work" bucket
     * (DashboardController::statsAvailability) previously borrowed
     * Bootstrap's own text-primary/bg-primary utility classes, which don't
     * track an owner's chosen accent (dark-theme.css repaints .text-primary
     * to --app-accent app-wide, but .bg-primary is left at Bootstrap's
     * stock blue) — a dedicated slot lets the widget use one real owner
     * color for both the label text and the bar consistently.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('work_color_key', 20)->nullable()->after('busy_color_key');
        });

        DB::statement('ALTER TABLE users DROP CONSTRAINT users_color_keys_check');

        $keys = "'".implode("', '", array_column(ColorSwatchKey::cases(), 'value'))."'";

        DB::statement(
            'ALTER TABLE users ADD CONSTRAINT users_color_keys_check CHECK ('.
            "(accent_color_key IS NULL OR accent_color_key IN ({$keys})) AND ".
            "(secondary_color_key IS NULL OR secondary_color_key IN ({$keys})) AND ".
            "(sleep_color_key IS NULL OR sleep_color_key IN ({$keys})) AND ".
            "(busy_color_key IS NULL OR busy_color_key IN ({$keys})) AND ".
            "(work_color_key IS NULL OR work_color_key IN ({$keys})) AND ".
            "(free_color_key IS NULL OR free_color_key IN ({$keys})) AND ".
            "(highlight_color_key IS NULL OR highlight_color_key IN ({$keys}))".
            ')'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_color_keys_check');

        $keys = "'".implode("', '", array_column(ColorSwatchKey::cases(), 'value'))."'";

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

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('work_color_key');
        });
    }
};
