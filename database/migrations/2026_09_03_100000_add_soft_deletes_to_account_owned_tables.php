<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Backs self-service account deletion: every table hanging off a user
     * gets a `deleted_at` so the whole graph can be soft-deleted immediately
     * (see App\Services\Account\AccountDeletionService), then hard-deleted
     * 48h later by App\Jobs\HardDeleteExpiredAccounts. Deliberately excludes
     * connection_attribute_values/connection_source_links (no direct
     * user_id, always reached through a non-deleted parent), share_link_cache
     * (a purely derived/re-computable artifact, deleted outright rather than
     * soft-deleted), sessions (no FK, live login state, deleted outright),
     * and password_reset_tokens (email-keyed, irrelevant to this graph).
     */
    public function up(): void
    {
        foreach ([
            'users',
            'sleep_exceptions',
            'activity_roles',
            'share_links',
            'share_link_words',
            'connections',
            'connection_sources',
            'connection_source_categories',
            'connection_attribute_definitions',
            'connection_edges',
            'invites',
            'invite_redemptions',
            'calendar_detections',
            'localized_texts',
        ] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'users',
            'sleep_exceptions',
            'activity_roles',
            'share_links',
            'share_link_words',
            'connections',
            'connection_sources',
            'connection_source_categories',
            'connection_attribute_definitions',
            'connection_edges',
            'invites',
            'invite_redemptions',
            'calendar_detections',
            'localized_texts',
        ] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }
};
