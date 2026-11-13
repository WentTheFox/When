<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remembers what Telegram said about each contact's photo, so photos can be fetched in the background
     * without the same contacts being asked about on every sync.
     *
     * has_photo: Telegram reports a profile photo for this contact (refreshed by every sync).
     * photo_checked_at: when we last asked Telegram for it, whether or not one came back (a photo hidden by
     * the contact's privacy settings comes back empty). Such a contact is not asked again for a while, so they
     * cannot hold up everyone queued behind them.
     */
    public function up(): void
    {
        Schema::table('telegram_friends', function (Blueprint $table) {
            $table->boolean('has_photo')->default(false);
            $table->timestampTz('photo_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('telegram_friends', function (Blueprint $table) {
            $table->dropColumn(['has_photo', 'photo_checked_at']);
        });
    }
};
