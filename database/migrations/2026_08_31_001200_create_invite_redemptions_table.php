<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Simple audit trail of inviter → invitee, not a reward system — see
     * PLAN.md "Future / explicitly deferred" for referral scoring.
     */
    public function up(): void
    {
        Schema::create('invite_redemptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('invite_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('redeemed_at');

            $table->unique(['invite_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invite_redemptions');
    }
};
