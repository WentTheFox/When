<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-link privacy toggle: whether this link's viewers see that an
     * unavailable block belongs to a recurring series, and how often it
     * repeats ("Every week"), even when they get no other detail about it.
     * Defaults true, same opt-out reasoning as show_activity.
     */
    public function up(): void
    {
        Schema::table('share_links', function (Blueprint $table) {
            $table->boolean('show_recurring')->default(true)->after('show_activity');
        });
    }

    public function down(): void
    {
        Schema::table('share_links', function (Blueprint $table) {
            $table->dropColumn('show_recurring');
        });
    }
};
