<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * users_color_keys_check/users_icon_keys_check/users_now_color_key_
     * check each baked a literal SQL IN(...) list into the schema at the
     * moment their defining migration ran — a second, DB-level copy of
     * whatever ColorSwatchKey/IconKey/NowColorPresetKey::cases() looked
     * like at that instant, which then has to be manually kept in sync
     * (drop + recreate the whole CHECK) every time a case is added. That
     * already broke a real save three times over in one afternoon
     * (Brown/Gold, Monochrome, and the new icon set all landing after
     * their respective CHECK last got rebuilt).
     *
     * Rather than keep re-deriving a DB-level copy of the same list (via
     * a Postgres native enum type instead of a CHECK, which has the exact
     * same "someone has to remember to update the DB too" problem, just
     * with `ALTER TYPE ... ADD VALUE` instead of drop/rebuild), the PHP
     * enum stays the *only* source of truth: SettingsController/
     * ConnectionSourceCategoryController already validate every one of
     * these columns with Rule::enum(...) before anything reaches the
     * database, so the CHECK was always a redundant second copy of that
     * same validation, not a safety net against anything the app layer
     * doesn't already catch. Dropping it removes the thing that was
     * actually going stale, without weakening real validation at all.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_color_keys_check');
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_icon_keys_check');
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_now_color_key_check');
    }

    public function down(): void
    {
        // Deliberately not restored — recreating these would just
        // reintroduce the exact staleness problem this migration exists
        // to remove. A rollback of this migration is a no-op; roll back
        // further (to before add_brown_and_gold_to_color_keys_check etc.)
        // if the CHECK-based approach is ever genuinely wanted back.
    }
};
