<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which linked account (Discord / VRChat / Telegram) stands for the connection: its picture in
     * every list, and its name and picture in the free page's header. Holds 'discord:<id>',
     * 'vrchat:<id>' or 'telegram:<id>' (APP_KEY-encrypted, §0.2 tier), or 'none' to show nobody;
     * null means automatic (the first account with a name). It used to live on share_links
     * (display_identity); that column stays until a later release drops it.
     */
    public function up(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->text('preferred_identity')->nullable();
        });

        // Both columns hold the same APP_KEY-encrypted payload, so the raw value moves as it is.
        DB::statement('UPDATE connections SET preferred_identity = share_links.display_identity FROM share_links WHERE share_links.connection_id = connections.id AND share_links.display_identity IS NOT NULL');
    }

    public function down(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->dropColumn('preferred_identity');
        });
    }
};
