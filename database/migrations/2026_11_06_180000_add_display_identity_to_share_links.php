<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which linked account (Discord / VRChat) of the link's connection is shown in the free
     * page's header. Holds 'discord:<id>' or 'vrchat:<id>' (APP_KEY-encrypted, §0.2 tier), or
     * 'none' to show nobody; null means automatic (the first account with a name).
     */
    public function up(): void
    {
        Schema::table('share_links', function (Blueprint $table) {
            $table->text('display_identity')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('share_links', function (Blueprint $table) {
            $table->dropColumn('display_identity');
        });
    }
};
