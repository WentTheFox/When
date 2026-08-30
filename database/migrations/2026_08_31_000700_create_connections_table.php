<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('source_id')->nullable()
                ->constrained('connection_sources')->nullOnDelete();

            // Name/notes — client-side E2EE per §0.1.
            $table->text('name_ciphertext');
            $table->text('notes_ciphertext')->nullable();

            // A UUID pointing at a share link reveals nothing on its own, so
            // this linkage stays a plaintext FK per §0.1's explicit carve-out.
            $table->foreignUuid('share_link_id')->nullable()
                ->constrained('share_links')->nullOnDelete();

            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connections');
    }
};
