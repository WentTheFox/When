<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 'identifier' definitions hold a value that is deliberately NOT E2EE
     * (§0.2 tier): it's meant to be read server-side later (e.g. matching a
     * connection to an external service's user ID). The value lives in
     * identifier_value, encrypted at rest via the model's 'encrypted' cast
     * (APP_KEY), while value_ciphertext — the E2EE column — becomes nullable
     * since an identifier row never has one.
     */
    public function up(): void
    {
        Schema::table('connection_attribute_values', function (Blueprint $table) {
            $table->text('value_ciphertext')->nullable()->change();
            $table->text('identifier_value')->nullable()->after('value_ciphertext');
        });

        DB::statement('ALTER TABLE connection_attribute_definitions DROP CONSTRAINT connection_attribute_definitions_type_check');
        DB::statement(
            'ALTER TABLE connection_attribute_definitions ADD CONSTRAINT '.
            'connection_attribute_definitions_type_check '.
            "CHECK (type IN ('text', 'textarea', 'date', 'number', 'url', 'email', 'phone', 'radio', 'identifier'))"
        );
    }

    public function down(): void
    {
        DB::table('connection_attribute_values')->whereNull('value_ciphertext')->delete();
        DB::table('connection_attribute_definitions')->where('type', 'identifier')->delete();

        DB::statement('ALTER TABLE connection_attribute_definitions DROP CONSTRAINT connection_attribute_definitions_type_check');
        DB::statement(
            'ALTER TABLE connection_attribute_definitions ADD CONSTRAINT '.
            'connection_attribute_definitions_type_check '.
            "CHECK (type IN ('text', 'textarea', 'date', 'number', 'url', 'email', 'phone', 'radio'))"
        );

        Schema::table('connection_attribute_values', function (Blueprint $table) {
            $table->dropColumn('identifier_value');
            $table->text('value_ciphertext')->nullable(false)->change();
        });
    }
};
