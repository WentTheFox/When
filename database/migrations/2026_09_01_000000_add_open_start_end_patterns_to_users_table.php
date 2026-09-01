<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('open_end_pattern')->nullable()->after('tentative_pattern');
            $table->text('open_start_pattern')->nullable()->after('open_end_pattern');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['open_end_pattern', 'open_start_pattern']);
        });
    }
};
