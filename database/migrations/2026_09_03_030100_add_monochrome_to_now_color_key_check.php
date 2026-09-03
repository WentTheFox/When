<?php

use App\Support\NowColorPresetKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Same "constraint bakes in a fixed list at migration-run time, not
     * something that tracks the enum afterwards" problem
     * add_brown_and_gold_to_color_keys_check just fixed for
     * users_color_keys_check — users_now_color_key_check needs the same
     * treatment now that NowColorPresetKey has a Monochrome case the
     * original replace_now_color_with_preset_key migration didn't know
     * about.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_now_color_key_check');

        $keys = "'".implode("', '", array_column(NowColorPresetKey::cases(), 'value'))."'";

        DB::statement(
            'ALTER TABLE users ADD CONSTRAINT users_now_color_key_check CHECK ('.
            "(now_color_key IS NULL OR now_color_key IN ({$keys}))".
            ')'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_now_color_key_check');

        $keys = "'".implode("', '", array_filter(
            array_column(NowColorPresetKey::cases(), 'value'),
            fn (string $key) => $key !== 'monochrome'
        ))."'";

        DB::statement(
            'ALTER TABLE users ADD CONSTRAINT users_now_color_key_check CHECK ('.
            "(now_color_key IS NULL OR now_color_key IN ({$keys}))".
            ')'
        );
    }
};
