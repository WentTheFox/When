<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connection_edges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('from_connection_id')
                ->constrained('connections')->cascadeOnDelete();
            $table->foreignUuid('to_connection_id')
                ->constrained('connections')->cascadeOnDelete();

            // Relationship label (e.g. "sibling of") — client-side E2EE per §0.1.
            $table->text('label_ciphertext')->nullable();

            $table->timestamps();

            $table->index('user_id');
            $table->unique(['from_connection_id', 'to_connection_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connection_edges');
    }
};
