<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * 'language' and 'timezone' definitions hold several choices at once (language codes such as "en", IANA zone
     * ids such as "Europe/Budapest"), as a plaintext list in connection_attribute_values.identifier_list_value like
     * 'identifier_list' (§0.2: APP_KEY-encrypted at rest, readable by the server on purpose, since they are meant
     * to move from share links to connections entirely). No new column, only the type constraint widens.
     */
    public function up(): void
    {
        $this->setTypes("'text', 'textarea', 'date', 'number', 'url', 'email', 'phone', 'radio', 'identifier', 'connection', 'identifier_list', 'boolean', 'country', 'list', 'link_list', 'relationship', 'language', 'timezone'");
    }

    public function down(): void
    {
        DB::table('connection_attribute_definitions')->whereIn('type', ['language', 'timezone'])->delete();
        $this->setTypes("'text', 'textarea', 'date', 'number', 'url', 'email', 'phone', 'radio', 'identifier', 'connection', 'identifier_list', 'boolean', 'country', 'list', 'link_list', 'relationship'");
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
