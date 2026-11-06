<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * users.alternative_names_definition_id picks the custom field that lists a connection's other
     * names (previous names, nicknames): an identifier_list (server-readable) or a multi-line
     * field (E2EE, one name per line). Only the browser ever reads it, to find a connection by
     * any of its names; the server just remembers which field it is. nullOnDelete so removing
     * the field quietly turns the feature off.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignUuid('alternative_names_definition_id')->nullable()
                ->constrained('connection_attribute_definitions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('alternative_names_definition_id');
        });
    }
};
