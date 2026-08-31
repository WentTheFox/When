<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stage 6: the public share view shows the owner's name, not any
     * WhenTheFox branding — but owners can override the page heading text
     * entirely (e.g. "Book time with me" instead of the default "{name}'s
     * Free Time"). Null means "use the computed default."
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('public_page_title')->nullable()->after('highlight_clause_pattern');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('public_page_title');
        });
    }
};
