<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pending friends are no longer handed to a browser one claim at a time: they sit
     * in a visible list until the owner acts on each, so there is nothing to claim.
     */
    public function up(): void
    {
        Schema::table('vrchat_friends', function (Blueprint $table) {
            $table->dropColumn('claimed_at');
        });
    }

    public function down(): void
    {
        Schema::table('vrchat_friends', function (Blueprint $table) {
            $table->timestampTz('claimed_at')->nullable();
        });
    }
};
