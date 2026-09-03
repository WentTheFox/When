<?php

use App\Support\IconKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Same "constraint bakes in a fixed list at migration-run time, not
     * something that tracks the enum afterwards" problem
     * add_brown_and_gold_to_color_keys_check just fixed for
     * users_color_keys_check — users_icon_keys_check needs the same
     * treatment now that IconKey grew from 33 to 48 cases (building/
     * people/free-busy-signal icons, plus Poop) after
     * add_icon_keys_to_users_table already ran.
     */
    private const NEW_KEYS_SINCE_ORIGINAL_MIGRATION = [
        'city', 'industry', 'warehouse', 'building-columns', 'user-tie',
        'handshake', 'people-group', 'door-open', 'door-closed',
        'toggle-on', 'toggle-off', 'signal', 'circle-check', 'circle-xmark',
        'poop',
    ];

    public function up(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_icon_keys_check');

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

        $keys = "'".implode("', '", array_filter(
            array_column(IconKey::cases(), 'value'),
            fn (string $key) => ! in_array($key, self::NEW_KEYS_SINCE_ORIGINAL_MIGRATION, true)
        ))."'";

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
};
