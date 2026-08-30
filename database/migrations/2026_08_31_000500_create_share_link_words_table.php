<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('share_link_words', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('share_link_id')->constrained()->cascadeOnDelete();

            // Highlight word/clause. Server needs this in plaintext to run the
            // "with/w/···" matcher against ICS event titles during recompute
            // (§5.1), so it's encrypted at rest with the same runtime-only key
            // as calendar_url (§0.2) — NOT the client vault key — and decrypted
            // transiently, only inside the recompute job.
            $table->text('word_ciphertext');

            $table->timestamps();

            $table->index('share_link_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('share_link_words');
    }
};
