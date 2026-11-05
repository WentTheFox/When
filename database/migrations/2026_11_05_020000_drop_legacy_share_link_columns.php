<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Contract step for 2026_11_05_010000_make_share_links_belong_to_connections.
     * Deploy only once that migration is live everywhere: nothing reads these
     * any more.
     *
     *  - connections.share_link_id: the old reverse pointer, replaced by
     *    share_links.connection_id.
     *  - share_links.label_ciphertext: a link is named after its connection.
     *  - share_links.bypass_dnd and the share_link_words table: the legacy
     *    per-link values, long since copied onto the connection's custom
     *    fields (a boolean and an identifier_list).
     */
    public function up(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('share_link_id');
        });

        Schema::table('share_links', function (Blueprint $table) {
            $table->dropColumn(['label_ciphertext', 'bypass_dnd']);
        });

        Schema::dropIfExists('share_link_words');
    }

    /** Restores the empty shapes only; the dropped data is not recoverable. */
    public function down(): void
    {
        Schema::create('share_link_words', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('share_link_id')->constrained()->cascadeOnDelete();
            $table->text('word_ciphertext');
            $table->timestamps();
            $table->softDeletes();

            $table->index('share_link_id');
        });

        Schema::table('share_links', function (Blueprint $table) {
            $table->text('label_ciphertext')->nullable();
            $table->boolean('bypass_dnd')->default(false);
        });

        Schema::table('connections', function (Blueprint $table) {
            $table->foreignUuid('share_link_id')->nullable()->constrained('share_links')->nullOnDelete();
        });
    }
};
