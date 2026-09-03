<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §5.0: a feed's sharing settings can change over time, so the
     * classification is stored per fetch, not just once — the owner should
     * see that reflected rather than being stuck on a stale classification.
     */
    public function up(): void
    {
        Schema::create('calendar_detections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('detected_mode', 20);
            $table->timestamp('fetched_at');

            $table->index(['user_id', 'fetched_at']);
        });

        DB::statement(
            'ALTER TABLE calendar_detections ADD CONSTRAINT calendar_detections_mode_check '.
            "CHECK (detected_mode IN ('full_detail', 'free_busy_only', 'mixed'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_detections');
    }
};
