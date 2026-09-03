<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connection_attribute_definitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();

            // Attribute label (e.g. "Birthday") — client-side E2EE per §0.1.
            // Defaults to encrypting labels too, since "connection details"
            // was named explicitly as in-scope for the E2EE guarantee.
            $table->text('label_ciphertext');

            // The attribute *type* (text/date/number/url/...) is a schema
            // shape, not user-authored content — stays plaintext so the UI can
            // pick the right input control without decrypting first.
            $table->string('type', 20)->default('text');

            $table->timestamps();

            $table->index('user_id');
        });

        DB::statement(
            'ALTER TABLE connection_attribute_definitions ADD CONSTRAINT '.
            'connection_attribute_definitions_type_check '.
            "CHECK (type IN ('text', 'date', 'number', 'url', 'email', 'phone'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('connection_attribute_definitions');
    }
};
