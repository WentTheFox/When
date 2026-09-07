<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per /free/{token} page view — timestamp plus the visitor's own
 * browser-reported IANA timezone (Intl.DateTimeFormat().resolvedOptions().timeZone),
 * sent client-side via POST /api/share/{token}/visits. Purely plaintext/
 * aggregate data (an IANA zone name and a timestamp reveal nothing about the
 * owner's calendar contents), so this sits outside both §0.1 and §0.2's
 * ciphertext tiers entirely — same treatment as show_activity/bypass_dnd.
 * Lets an owner eyeball time-zone spread across viewers of a given link from
 * the dashboard (ShareLinkManagementController::visits()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('share_link_visits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('share_link_id')->constrained()->cascadeOnDelete();
            $table->string('timezone');
            $table->timestamp('visited_at')->useCurrent();

            $table->index(['share_link_id', 'visited_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('share_link_visits');
    }
};
