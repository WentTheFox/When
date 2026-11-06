<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * 'country' definitions hold an ISO 3166-1 alpha-2 code (e.g. "DE"). It is
     * an ordinary E2EE value in value_ciphertext (§0.1): no new column, only
     * the type constraint widens.
     */
    public function up(): void
    {
        $this->setTypes("'text', 'textarea', 'date', 'number', 'url', 'email', 'phone', 'radio', 'identifier', 'connection', 'identifier_list', 'boolean', 'country'");
    }

    public function down(): void
    {
        DB::table('connection_attribute_definitions')->where('type', 'country')->delete();
        $this->setTypes("'text', 'textarea', 'date', 'number', 'url', 'email', 'phone', 'radio', 'identifier', 'connection', 'identifier_list', 'boolean'");
    }

    private function setTypes(string $list): void
    {
        DB::statement('ALTER TABLE connection_attribute_definitions DROP CONSTRAINT connection_attribute_definitions_type_check');
        DB::statement(
            'ALTER TABLE connection_attribute_definitions ADD CONSTRAINT '.
            'connection_attribute_definitions_type_check '."CHECK (type IN ($list))"
        );
    }
};
