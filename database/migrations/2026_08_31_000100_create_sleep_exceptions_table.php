<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sleep_exceptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            // Owner's free-text note about the exception (e.g. "on vacation") —
            // client-side E2EE per §0.1. The date range stays plaintext since it
            // drives server-side sleep computation (§5.1).
            $table->text('label_ciphertext')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'start_date', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sleep_exceptions');
    }
};
