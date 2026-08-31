<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 0=Sunday..6=Saturday (date-fns' own weekStartsOn convention, used
     * directly by the frontend with no translation needed). Defaults to
     * Monday (1) — this app's existing week views were already hardcoded
     * to start on Monday before this became configurable.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedTinyInteger('week_start')->default(1)->after('timezone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('week_start');
        });
    }
};
