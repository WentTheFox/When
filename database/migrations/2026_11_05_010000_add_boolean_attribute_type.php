<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 'boolean' definitions hold a yes/no that is deliberately NOT E2EE
     * (§0.2 tier) so the server can act on it (share-link "bypass DND").
     * boolean_value is APP_KEY-encrypted at rest (App\Casts\EncryptedBoolean);
     * value_ciphertext stays null for such rows.
     *
     * users.bypass_dnd_definition_id picks the boolean definition that
     * supplies bypass-DND for each link's tied connection. Null keeps the
     * share_links.bypass_dnd flag. nullOnDelete: removing the definition
     * reverts to that fallback.
     */
    public function up(): void
    {
        Schema::table('connection_attribute_values', function (Blueprint $table) {
            $table->text('boolean_value')->nullable()->after('identifier_list_value');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignUuid('bypass_dnd_definition_id')->nullable()
                ->constrained('connection_attribute_definitions')->nullOnDelete();
        });

        $this->setTypes("'text', 'textarea', 'date', 'number', 'url', 'email', 'phone', 'radio', 'identifier', 'connection', 'identifier_list', 'boolean'");
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bypass_dnd_definition_id');
        });

        DB::table('connection_attribute_values')->whereNotNull('boolean_value')->delete();
        DB::table('connection_attribute_definitions')->where('type', 'boolean')->delete();

        Schema::table('connection_attribute_values', function (Blueprint $table) {
            $table->dropColumn('boolean_value');
        });

        $this->setTypes("'text', 'textarea', 'date', 'number', 'url', 'email', 'phone', 'radio', 'identifier', 'connection', 'identifier_list'");
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
