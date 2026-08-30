<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('share_link_cache', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('share_link_id')->constrained()->cascadeOnDelete();

            // The pre-encrypted, ready-to-serve result (§0.2). Encrypted with
            // the share link's own content key immediately after computation —
            // this is the only form of the computed free/busy result that ever
            // touches storage; plaintext slots exist only for the duration of
            // the recompute job.
            $table->text('ciphertext');

            $table->timestamp('computed_range_start');
            $table->timestamp('computed_range_end');
            $table->timestamp('encrypted_at');

            $table->timestamps();

            $table->unique('share_link_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('share_link_cache');
    }
};
