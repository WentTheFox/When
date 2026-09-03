<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::rename('activity_roles', 'activity_localizations');

        // localized_texts.localizable_type stores the owning model's FQCN as
        // a literal string (no morph map defined) — renaming the table alone
        // leaves every existing activity-role label row pointing at a class
        // that no longer exists, silently orphaning it from
        // ActivityLocalization::localizedTexts()'s query (which filters by
        // the *current* class name). Must be updated in lockstep with the
        // table rename.
        DB::table('localized_texts')
            ->where('localizable_type', 'App\\Models\\ActivityRole')
            ->update(['localizable_type' => 'App\\Models\\ActivityLocalization']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('localized_texts')
            ->where('localizable_type', 'App\\Models\\ActivityLocalization')
            ->update(['localizable_type' => 'App\\Models\\ActivityRole']);

        Schema::rename('activity_localizations', 'activity_roles');
    }
};
