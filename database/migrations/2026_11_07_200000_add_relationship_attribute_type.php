<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * 'relationship' definitions list the connections a connection is related to, as ONE ordinary
     * E2EE value in value_ciphertext (§0.1): a newline-separated, padded list of connection ids (like 'list'), under the
     * parent connection's own key. The server cannot see who is related to whom or how many.
     * Relationships are mirrored (A lists B ⇒ B lists A in the same field); the browser writes
     * both sides in one save. No new column, only the type constraint widens.
     */
    public function up(): void
    {
        $this->setTypes("'text', 'textarea', 'date', 'number', 'url', 'email', 'phone', 'radio', 'identifier', 'connection', 'identifier_list', 'boolean', 'country', 'list', 'link_list', 'relationship'");
    }

    public function down(): void
    {
        DB::table('connection_attribute_definitions')->where('type', 'relationship')->delete();
        $this->setTypes("'text', 'textarea', 'date', 'number', 'url', 'email', 'phone', 'radio', 'identifier', 'connection', 'identifier_list', 'boolean', 'country', 'list', 'link_list'");
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
