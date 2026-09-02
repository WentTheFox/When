<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Same mechanism as dnd_event_name/nap_event_name (a regex-fragment
     * pattern matched via ParsedEvent::matchesEventNamePattern) — used by
     * the owner-facing dashboard time-breakdown widget to classify events
     * as "work" rather than plain busy time. Plaintext, not ciphertext:
     * same tier as dnd/nap event names, which are also owner-authored
     * event-title patterns, not calendar content itself.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('work_event_name')->nullable()->after('nap_event_name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('work_event_name');
        });
    }
};
