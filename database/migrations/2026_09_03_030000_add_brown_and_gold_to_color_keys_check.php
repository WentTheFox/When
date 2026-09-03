<?php

use App\Support\ColorSwatchKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * users_color_keys_check bakes ColorSwatchKey::cases() into a literal
     * SQL IN(...) list at the moment this migration runs, not something
     * that automatically tracks the enum afterwards — every time a new
     * swatch is added (Brown/Gold here) the constraint has to be dropped
     * and recreated against the current case list, same as
     * add_work_color_key_to_users_table did when it added the seventh
     * slot. Skipping this step is exactly what caused a save to start
     * failing with a users_color_keys_check violation as soon as
     * ColorPalette::DEFAULT_KEYS started pointing 'work'/'highlighted' at
     * the two new swatches the constraint didn't know about yet.
     */
    public function up(): void
    {
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

        $keys = "'".implode("', '", array_filter(
            array_column(ColorSwatchKey::cases(), 'value'),
            fn (string $key) => ! in_array($key, ['brown', 'gold'], true)
        ))."'";

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
};
