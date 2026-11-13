<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Telegram contacts import (§0.2 tier, APP_KEY-encrypted at rest; the browser alone can encrypt a
     * connection's name, so it does the linking). Optional: only used when a Telegram sidecar is configured.
     *
     * users.telegram_session: the owner's Telethon StringSession, a full-account credential, so it is
     * encrypted (cast) and hidden like the Discord tokens. telegram_user_id_definition_id is the identifier /
     * identifier-list custom field chosen for Telegram user ids (registered in FieldFeatures).
     *
     * telegram_friends remembers every contact a sync has seen, so a contact is only ever "new" once: 'pending'
     * (waiting for the owner), 'created' (linked to a connection) or 'dismissed'. telegram_user_id_hash is the
     * keyed lookup key, as for Discord and VRChat.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('telegram_session')->nullable();
            $table->text('telegram_account_name')->nullable();
            $table->timestampTz('telegram_synced_at')->nullable();
            $table->foreignUuid('telegram_user_id_definition_id')->nullable()
                ->constrained('connection_attribute_definitions')->nullOnDelete();
        });

        Schema::create('telegram_friends', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('telegram_user_id_hash', 64);
            $table->text('telegram_user_id');
            $table->text('username')->nullable();
            $table->text('first_name')->nullable();
            $table->text('last_name')->nullable();
            $table->text('phone')->nullable();
            // A small profile-photo thumbnail as a data URL (Telegram has no public avatar URL).
            $table->text('avatar_url')->nullable();
            $table->boolean('is_mutual')->default(false);
            $table->boolean('is_premium')->default(false);
            $table->string('status', 16)->default('pending');
            $table->timestampTz('synced_at')->nullable();
            $table->timestampsTz();

            $table->unique(['user_id', 'telegram_user_id_hash']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_friends');

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('telegram_user_id_definition_id');
            $table->dropColumn(['telegram_session', 'telegram_account_name', 'telegram_synced_at']);
        });
    }
};
