<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manual tags (a weekday+time-window fallback for free_busy_only feeds)
 * were removed — HighlightMatcher's free_busy_only path now only ever
 * falls back to LOCATION, never a manually configured time block.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('share_link_manual_tags');
    }

    public function down(): void
    {
        Schema::create('share_link_manual_tags', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('share_link_id')->constrained()->cascadeOnDelete();
            $table->text('word_ciphertext');
            $table->unsignedTinyInteger('weekday')->nullable();
            $table->time('start_time');
            $table->time('end_time');
            $table->timestamps();
            $table->index('share_link_id');
        });
    }
};
