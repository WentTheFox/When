<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A source's logo: an optional icon (a palette icon key, `flag:xx` or `brand:xx`) and color. Both are set on
     * the category first, and a source inherits them unless it sets its own. Null everywhere means no icon and
     * the old plain color dot, so existing data looks as it did.
     *
     *  - icon_ciphertext (categories and sources): the icon key, encrypted in the browser with the record's own
     *    key like its name (§0.1).
     *  - color_key (sources only; categories already have one): a palette KEY, plaintext by design like the
     *    category's — never a hex (see ColorSwatchKey).
     */
    public function up(): void
    {
        Schema::table('connection_source_categories', function (Blueprint $table) {
            $table->text('icon_ciphertext')->nullable();
        });

        Schema::table('connection_sources', function (Blueprint $table) {
            $table->text('icon_ciphertext')->nullable();
            $table->string('color_key')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('connection_sources', function (Blueprint $table) {
            $table->dropColumn(['icon_ciphertext', 'color_key']);
        });

        Schema::table('connection_source_categories', function (Blueprint $table) {
            $table->dropColumn('icon_ciphertext');
        });
    }
};
