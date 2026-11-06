<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * New-friend detection from VRCX's /api/v1/friends (§0.2 tier, like the
     * rest of the VRChat data).
     *
     * vrchat_friends remembers every friend the sync has seen, so a friend is
     * only ever "new" once: status 'pending' (waiting for the browser, which
     * alone can encrypt a connection's name, to create the connection),
     * 'created' (linked to a connection) or 'dismissed' (already a friend when
     * the feature was switched on). Deleting a connection later does not make
     * its friend pending again. claimed_at marks a pending row a browser is
     * working on, so two open tabs don't both create the connection.
     *
     * users.vrcx_auto_create_friends is the opt-in; vrcx_friends_baseline_at is
     * set by the first friends sync after it is switched on.
     * vrcx_friends_source_id is the connection source every connection made
     * from a new friend is tied to (null = none; nullOnDelete, so deleting the
     * source simply stops tagging).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('vrcx_auto_create_friends')->default(false);
            $table->timestampTz('vrcx_friends_baseline_at')->nullable();
            $table->foreignUuid('vrcx_friends_source_id')->nullable()->constrained('connection_sources')->nullOnDelete();
        });

        Schema::create('vrchat_friends', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('vrchat_user_id_hash', 64);
            $table->text('vrchat_user_id');
            $table->text('display_name')->nullable();
            $table->text('profile_picture_url')->nullable();
            $table->string('status', 16)->default('pending');
            $table->timestampTz('claimed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['user_id', 'vrchat_user_id_hash']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vrchat_friends');

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vrcx_friends_source_id');
            $table->dropColumn(['vrcx_auto_create_friends', 'vrcx_friends_baseline_at']);
        });
    }
};
