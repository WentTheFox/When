<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Existing friends get connections too, so there is no "baseline" of friends
     * to leave alone any more. Friends that baseline had marked 'dismissed' go
     * back to 'pending', so the browser handles them like any other: it links
     * each to a matching connection (same name, or a highlight token) or creates
     * one. From now on 'dismissed' means only "matched several connections, left
     * for the owner to sort out".
     */
    public function up(): void
    {
        DB::table('vrchat_friends')->where('status', 'dismissed')->update(['status' => 'pending', 'claimed_at' => null]);

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('vrcx_friends_baseline_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestampTz('vrcx_friends_baseline_at')->nullable();
        });
    }
};
