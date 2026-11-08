<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When Discord last confirmed this person's profile. Null means the names and avatar came from something the
     * owner pasted in (an imported relationships list) and have not been checked with Discord yet.
     * Everything stored before this column came from Discord, so it counts as synced when it was last written.
     */
    public function up(): void
    {
        Schema::table('discord_friends', function (Blueprint $table) {
            $table->timestampTz('synced_at')->nullable();
        });

        DB::table('discord_friends')->update(['synced_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('discord_friends', function (Blueprint $table) {
            $table->dropColumn('synced_at');
        });
    }
};
