<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Connection notes used to be a built-in column (connections.notes_ciphertext); they are an
     * ordinary multi-line custom field now. This remembers which field that is, so moving the
     * old notes into it (ConnectionController::notesField) and the VRChat note import always
     * land in the same one. A plain uuid, no foreign key: the owner may delete the field.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('notes_field_definition_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notes_field_definition_id');
        });
    }
};
