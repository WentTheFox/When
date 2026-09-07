<?php

use App\Models\ActivityLocalization;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Str;

/**
 * One-time data migration for the work/school dedicated-pattern-settings
 * removal: every user with a work_event_pattern/school_event_pattern
 * configured gets an equivalent activity_localizations row, pattern/icon/
 * color carried over as-is, same as any other owner-added customization
 * (e.g. the built-in Host/Visit roles) — no dedicated "work"/"school"
 * category or matching behavior survives this, just the raw data. Must
 * run before the next migration drops the source columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        User::query()
            ->where(function ($query) {
                $query->whereNotNull('work_event_pattern')->orWhereNotNull('school_event_pattern');
            })
            ->orderBy('id')
            ->chunkById(100, function ($users) {
                foreach ($users as $user) {
                    $sortOrder = (int) ($user->activityLocalizations()->max('sort_order') ?? -1) + 1;

                    if ($user->work_event_pattern) {
                        ActivityLocalization::create([
                            'id' => (string) Str::uuid(),
                            'user_id' => $user->id,
                            'pattern' => $user->work_event_pattern,
                            'pattern_preview' => $user->work_event_pattern_preview,
                            'sort_order' => $sortOrder++,
                            'icon_key' => $user->work_icon_key,
                            'color_key' => $user->work_color_key,
                        ]);
                    }

                    if ($user->school_event_pattern) {
                        ActivityLocalization::create([
                            'id' => (string) Str::uuid(),
                            'user_id' => $user->id,
                            'pattern' => $user->school_event_pattern,
                            'pattern_preview' => $user->school_event_pattern_preview,
                            'sort_order' => $sortOrder,
                            'icon_key' => $user->school_icon_key,
                            'color_key' => $user->school_color_key,
                        ]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Irreversible: the migrated rows are indistinguishable from any
        // other activity_localizations row an owner may have added since.
    }
};
