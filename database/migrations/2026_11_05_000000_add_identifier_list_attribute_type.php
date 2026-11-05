<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 'identifier_list' definitions are the list-valued sibling of
     * 'identifier': deliberately NOT E2EE (§0.2 tier), so the server can use
     * the values (share-link highlight tokens). The value is a JSON array
     * stored in identifier_list_value, APP_KEY-encrypted at rest via the
     * model's 'encrypted:array' cast; value_ciphertext and identifier_value
     * stay null for such rows.
     *
     * users.highlight_identifier_definition_id picks which identifier_list
     * definition supplies the highlight tokens. Null keeps the legacy
     * share_link_words behavior. nullOnDelete so removing the definition
     * silently reverts to that fallback rather than breaking recomputes.
     */
    public function up(): void
    {
        Schema::table('connection_attribute_values', function (Blueprint $table) {
            $table->text('identifier_list_value')->nullable()->after('identifier_value');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignUuid('highlight_identifier_definition_id')->nullable()
                ->constrained('connection_attribute_definitions')->nullOnDelete();
        });

        $this->setTypes("'text', 'textarea', 'date', 'number', 'url', 'email', 'phone', 'radio', 'identifier', 'connection', 'identifier_list'");
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('highlight_identifier_definition_id');
        });

        DB::table('connection_attribute_values')->whereNotNull('identifier_list_value')->delete();
        DB::table('connection_attribute_definitions')->where('type', 'identifier_list')->delete();

        Schema::table('connection_attribute_values', function (Blueprint $table) {
            $table->dropColumn('identifier_list_value');
        });

        $this->setTypes("'text', 'textarea', 'date', 'number', 'url', 'email', 'phone', 'radio', 'identifier', 'connection'");
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
