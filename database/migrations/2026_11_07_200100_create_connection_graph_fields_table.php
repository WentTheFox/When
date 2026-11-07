<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which relationship (or connection) fields feed the dashboard network-graph widget. Only
     * field ids live here, never anything about the connections themselves (§0.1). Also records
     * when the owner's old plaintext edges were moved into a relationship field.
     */
    public function up(): void
    {
        Schema::create('connection_graph_fields', function (Blueprint $table) {
            $table->uuid('user_id');
            $table->uuid('definition_id');
            $table->timestampTz('created_at')->nullable();

            $table->primary(['user_id', 'definition_id']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('definition_id')->references('id')->on('connection_attribute_definitions')->cascadeOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            // A plain uuid like notes_field_definition_id: the field the old edges were moved into, so a
            // move interrupted halfway resumes into the same one.
            $table->uuid('relationships_field_definition_id')->nullable();
            $table->timestampTz('relationships_migrated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['relationships_field_definition_id', 'relationships_migrated_at']);
        });
        Schema::dropIfExists('connection_graph_fields');
    }
};
