<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §5.0's fallback for free_busy_only/mixed feeds: word/clause matching
     * against titles is meaningless when there are no real titles, so the
     * owner can manually mark a specific recurring time block as "this is
     * time with [word]" independent of the feed's content. Full CRUD UI
     * lands in Stage 7 — this is just the data the matcher consumes.
     */
    public function up(): void
    {
        Schema::create('share_link_manual_tags', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('share_link_id')->constrained()->cascadeOnDelete();

            // Same tier as share_link_words — server needs this in plaintext
            // to attach it to computed slots during recompute.
            $table->text('word_ciphertext');

            // 0 (Sunday) .. 6 (Saturday). Null means "every day."
            $table->unsignedTinyInteger('weekday')->nullable();
            $table->time('start_time');
            $table->time('end_time');

            $table->timestamps();

            $table->index('share_link_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('share_link_manual_tags');
    }
};
