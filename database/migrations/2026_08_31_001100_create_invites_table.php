<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invites', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('inviter_user_id')->constrained('users')->cascadeOnDelete();

            $table->string('code')->unique();
            // Null max_uses = unlimited until expiry.
            $table->unsignedInteger('max_uses')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            // Set when this invite was generated from a viewer clicking "create
            // your own" off someone's share link — attributes the invite back
            // to the calendar that surfaced it.
            $table->foreignUuid('source_share_link_id')->nullable()
                ->constrained('share_links')->nullOnDelete();

            $table->timestamps();

            $table->index('inviter_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invites');
    }
};
