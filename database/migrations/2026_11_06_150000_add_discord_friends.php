<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Discord friends import (§0.2 tier, APP_KEY-encrypted at rest; the browser
     * alone can encrypt a connection's name, so it does the linking).
     *
     * users.discord_*: the OAuth2 tokens (identify + relationships.read), the
     * account they belong to (for display), and when the friends were last synced.
     *
     * discord_friends remembers every friend the sync has seen, so a friend is
     * only ever "new" once: 'pending' (waiting for the owner), 'created' (linked
     * to a connection) or 'dismissed'. discord_user_id_hash is the keyed lookup
     * key, as vrchat_user_id_hash is for VRChat.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('discord_access_token')->nullable();
            $table->text('discord_refresh_token')->nullable();
            $table->timestampTz('discord_token_expires_at')->nullable();
            $table->text('discord_account_name')->nullable();
            $table->timestampTz('discord_synced_at')->nullable();
        });

        Schema::create('discord_friends', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('discord_user_id_hash', 64);
            $table->text('discord_user_id');
            $table->text('username')->nullable();
            $table->text('global_name')->nullable();
            // The owner's own private nickname for the friend, if they set one in Discord.
            $table->text('nickname')->nullable();
            $table->text('avatar_url')->nullable();
            $table->string('status', 16)->default('pending');
            $table->timestampsTz();

            $table->unique(['user_id', 'discord_user_id_hash']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discord_friends');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'discord_access_token', 'discord_refresh_token', 'discord_token_expires_at',
                'discord_account_name', 'discord_synced_at',
            ]);
        });
    }
};
