<?php

use App\Support\IconKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Same KEY-into-a-curated-palette scheme as the *_color_key columns
     * (see replace_owner_colors_with_palette_keys/add_work_color_key_to_
     * users_table) — five slots, one per calendar block type. No accent/
     * secondary/now_color equivalents here: those aren't block types with
     * their own icon.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('free_icon_key', 20)->nullable()->after('free_color_key');
            $table->string('busy_icon_key', 20)->nullable()->after('busy_color_key');
            $table->string('work_icon_key', 20)->nullable()->after('work_color_key');
            $table->string('sleep_icon_key', 20)->nullable()->after('sleep_color_key');
            $table->string('highlight_icon_key', 20)->nullable()->after('highlight_color_key');
        });

        $keys = "'".implode("', '", array_column(IconKey::cases(), 'value'))."'";

        DB::statement(
            'ALTER TABLE users ADD CONSTRAINT users_icon_keys_check CHECK ('.
            "(free_icon_key IS NULL OR free_icon_key IN ({$keys})) AND ".
            "(busy_icon_key IS NULL OR busy_icon_key IN ({$keys})) AND ".
            "(work_icon_key IS NULL OR work_icon_key IN ({$keys})) AND ".
            "(sleep_icon_key IS NULL OR sleep_icon_key IN ({$keys})) AND ".
            "(highlight_icon_key IS NULL OR highlight_icon_key IN ({$keys}))".
            ')'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_icon_keys_check');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'free_icon_key',
                'busy_icon_key',
                'work_icon_key',
                'sleep_icon_key',
                'highlight_icon_key',
            ]);
        });
    }
};
