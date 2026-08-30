<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connection_attribute_values', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('connection_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('attribute_definition_id')
                ->constrained('connection_attribute_definitions')->cascadeOnDelete();

            // Value — client-side E2EE per §0.1.
            $table->text('value_ciphertext');

            $table->timestamps();

            $table->unique(['connection_id', 'attribute_definition_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connection_attribute_values');
    }
};
