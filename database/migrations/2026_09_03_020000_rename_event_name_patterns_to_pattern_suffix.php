<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `dnd_event_name`/`nap_event_name`/`work_event_name` never actually
     * held a literal event *name* — same regex-body-matched-against-the-
     * title convention as highlight_clause_pattern/tentative_pattern/
     * open_end_pattern/open_start_pattern/highlight_split_pattern (see
     * ParsedEvent::matchesEventNamePattern), just missing the `_pattern`
     * suffix the others all have. "^dnd$" or "meeting|call" in this field
     * was never a name to match verbatim — the old name misled an owner
     * into expecting exactly that.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('dnd_event_name', 'dnd_event_pattern');
            $table->renameColumn('nap_event_name', 'nap_event_pattern');
            $table->renameColumn('work_event_name', 'work_event_pattern');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('dnd_event_pattern', 'dnd_event_name');
            $table->renameColumn('nap_event_pattern', 'nap_event_name');
            $table->renameColumn('work_event_pattern', 'work_event_name');
        });
    }
};
