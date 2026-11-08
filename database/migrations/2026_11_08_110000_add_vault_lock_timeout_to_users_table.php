<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Minutes of inactivity after which the dashboard re-locks the vault
     * (clears the persisted vault key). NULL = never auto-lock. Not
     * sensitive — same tier as the other display/behaviour settings.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedSmallInteger('vault_lock_timeout_minutes')->nullable()->default(15)->after('week_start');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('vault_lock_timeout_minutes');
        });
    }
};
