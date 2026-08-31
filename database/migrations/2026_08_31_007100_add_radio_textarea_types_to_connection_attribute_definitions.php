<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 'radio' (a fixed set of choices, e.g. "Standing": friend/acquaintance/
     * hostile/...) and 'textarea' (multi-line free text) — needed to import
     * the source app connection data losslessly. options_ciphertext holds a
     * JSON-encoded {"choices": [...]} for 'radio' definitions, encrypted
     * with the *definition's* own vault key (same key as label_ciphertext,
     * not the connection's) since it's a property of the definition, not
     * of any one connection's data.
     */
    public function up(): void
    {
        Schema::table('connection_attribute_definitions', function (Blueprint $table) {
            $table->text('options_ciphertext')->nullable()->after('type');
        });

        DB::statement('ALTER TABLE connection_attribute_definitions DROP CONSTRAINT connection_attribute_definitions_type_check');
        DB::statement(
            "ALTER TABLE connection_attribute_definitions ADD CONSTRAINT ".
            "connection_attribute_definitions_type_check ".
            "CHECK (type IN ('text', 'textarea', 'date', 'number', 'url', 'email', 'phone', 'radio'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE connection_attribute_definitions DROP CONSTRAINT connection_attribute_definitions_type_check');
        DB::statement(
            "ALTER TABLE connection_attribute_definitions ADD CONSTRAINT ".
            "connection_attribute_definitions_type_check ".
            "CHECK (type IN ('text', 'date', 'number', 'url', 'email', 'phone'))"
        );

        Schema::table('connection_attribute_definitions', function (Blueprint $table) {
            $table->dropColumn('options_ciphertext');
        });
    }
};
