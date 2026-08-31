<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * public_page_title becomes two locale-specific columns — an owner can
     * now set a different heading per language instead of one title shown
     * regardless of which the visitor sees. Existing values migrate to
     * _en (the only locale the app has ever rendered so far).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('public_page_title_en')->nullable()->after('public_page_title');
            $table->string('public_page_title_hu')->nullable()->after('public_page_title_en');
        });

        DB::table('users')->whereNotNull('public_page_title')->update([
            'public_page_title_en' => DB::raw('public_page_title'),
        ]);

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('public_page_title');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('public_page_title')->nullable()->after('highlight_clause_pattern');
        });

        DB::table('users')->whereNotNull('public_page_title_en')->update([
            'public_page_title' => DB::raw('public_page_title_en'),
        ]);

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['public_page_title_en', 'public_page_title_hu']);
        });
    }
};
