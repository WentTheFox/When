<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Same plaintext-outside-both-tiers treatment as `timezone` (see the
 * create-table migration's doc comment) — an owner's viewer's browser
 * locale reveals nothing about the owner's calendar contents — but stored
 * encrypted (`Crypt`/`APP_KEY`) for the same reason `timezone` was migrated
 * to encrypted: neither column is ever grouped/filtered on in SQL (the
 * owner-preview visitor-timezone aggregation in ShareLinkVisitController
 * decrypts and groups in PHP), so encrypting it costs nothing query-wise.
 * Nullable: existing rows have none, and it's best-effort from the browser
 * (Intl.DateTimeFormat().resolvedOptions().locale) rather than required.
 *
 * The existing (share_link_id, visited_at) index already covers both the
 * dashboard's paginated "latest visits" query and the owner-preview
 * aggregation's "most recent N visits for this link" fetch — an index on
 * `locale` itself would be useless anyway since the column is ciphertext.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('share_link_visits', function (Blueprint $table) {
            $table->text('locale')->nullable()->after('timezone');
        });
    }

    public function down(): void
    {
        Schema::table('share_link_visits', function (Blueprint $table) {
            $table->dropColumn('locale');
        });
    }
};
