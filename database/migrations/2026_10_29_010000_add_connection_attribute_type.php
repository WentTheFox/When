<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * 'connection' definitions hold a reference to another of the owner's
     * connections. The referenced id lives inside value_ciphertext (E2EE, like
     * every other value), so the server can't foreign-key it or cascade on
     * delete — orphans are handled client-side (see Connections.vue).
     */
    public function up(): void
    {
        $this->setTypes("'text', 'textarea', 'date', 'number', 'url', 'email', 'phone', 'radio', 'identifier', 'connection'");
    }

    public function down(): void
    {
        DB::table('connection_attribute_definitions')->where('type', 'connection')->delete();
        $this->setTypes("'text', 'textarea', 'date', 'number', 'url', 'email', 'phone', 'radio', 'identifier'");
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
