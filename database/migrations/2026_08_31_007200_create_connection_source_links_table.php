<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Replaces connections.source_id (one source per connection) with a
     * many-to-many pivot — the source app's own data model allows a person to
     * be linked to more than one source/group, and this is the literal
     * source of truth being imported, so the schema has to support it.
     * No production data exists yet in either column, so this is a clean
     * cutover rather than a backfill.
     */
    public function up(): void
    {
        Schema::create('connection_source_links', function (Blueprint $table) {
            $table->foreignUuid('connection_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('source_id')->constrained('connection_sources')->cascadeOnDelete();
            $table->primary(['connection_id', 'source_id']);
        });

        Schema::table('connections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_id');
        });
    }

    public function down(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->foreignUuid('source_id')->nullable()
                ->constrained('connection_sources')->nullOnDelete();
        });

        Schema::dropIfExists('connection_source_links');
    }
};
