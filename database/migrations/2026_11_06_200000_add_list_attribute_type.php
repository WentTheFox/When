<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * 'list' definitions hold a list of entries, one per line, as ONE ordinary E2EE value in
     * value_ciphertext (§0.1): the server cannot see the entries or how many there are. No new
     * column, only the type constraint widens.
     */
    public function up(): void
    {
        $this->setTypes("'text', 'textarea', 'date', 'number', 'url', 'email', 'phone', 'radio', 'identifier', 'connection', 'identifier_list', 'boolean', 'country', 'list'");
    }

    public function down(): void
    {
        DB::table('connection_attribute_definitions')->where('type', 'list')->delete();
        $this->setTypes("'text', 'textarea', 'date', 'number', 'url', 'email', 'phone', 'radio', 'identifier', 'connection', 'identifier_list', 'boolean', 'country'");
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
