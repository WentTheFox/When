<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Replaces the fixed `_en`/`_hu` column pair with a single JSON
     * object (`{"default": "...", "hu": "..."}`, see App\Support\
     * LocalizedText) — adding a third language later is then just a new
     * key an owner types in, not a new column/migration/backend field.
     * `_en`'s old value becomes `default` (not literally "the English
     * one" going forward, just "shown when there's no override for the
     * viewer's own locale") — existing data carries over exactly, since
     * every current row's `_en` was already the fallback shown to a
     * non-Hungarian viewer.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->jsonb('public_page_title')->nullable()->after('public_page_title_hu');
        });

        DB::statement(
            'UPDATE users SET public_page_title = jsonb_strip_nulls('.
            "jsonb_build_object('default', public_page_title_en, 'hu', public_page_title_hu)".
            ')'
        );
        DB::statement("UPDATE users SET public_page_title = NULL WHERE public_page_title = '{}'::jsonb");

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['public_page_title_en', 'public_page_title_hu']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('public_page_title_en')->nullable();
            $table->string('public_page_title_hu')->nullable();
        });

        DB::statement("UPDATE users SET public_page_title_en = public_page_title->>'default'");
        DB::statement("UPDATE users SET public_page_title_hu = public_page_title->>'hu'");

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('public_page_title');
        });
    }
};
