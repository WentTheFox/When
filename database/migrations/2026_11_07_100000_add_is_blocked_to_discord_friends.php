<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Blocked on Discord becomes a flag next to the status instead of a status of its own, so a blocked
     * person goes through the same pending / created / dismissed flow as a friend (and the sync never
     * clobbers that status). Rows that were 'blocked' become pending; the next sync settles any that
     * turn out to be linked already.
     */
    public function up(): void
    {
        Schema::table('discord_friends', function (Blueprint $table) {
            $table->boolean('is_blocked')->default(false);
        });

        DB::table('discord_friends')->where('status', 'blocked')->update(['is_blocked' => true, 'status' => 'pending']);
    }

    public function down(): void
    {
        DB::table('discord_friends')->where('is_blocked', true)->where('status', 'pending')->update(['status' => 'blocked']);

        Schema::table('discord_friends', function (Blueprint $table) {
            $table->dropColumn('is_blocked');
        });
    }
};
