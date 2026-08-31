<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Collapses the two share-link key mechanisms into one: every share link's
 * content key is now always derived deterministically from its own
 * `legacy_token ?? id` (§0.5's App\Services\Crypto\LegacyShareLinkKey,
 * previously only used for pre-migration links), so there's no longer a
 * separate random, server-stored key to protect via a URL fragment or an
 * Argon2id(passphrase)-wrapped blob. Drops `content_key_ciphertext`,
 * `key_protection`, `wrapped_key`, `wrap_salt`.
 *
 * Any already-cached ShareLinkAvailability result for a non-legacy link was
 * encrypted under its old random key, which this migration makes
 * unrecoverable (it was never derivable, only stored — that's the whole
 * column being dropped). Clearing share_link_cache here means the next
 * viewer request just recomputes fresh under the new derived key, same
 * self-healing path as any other stale-cache request, rather than serving
 * ciphertext nothing can decrypt anymore.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('share_link_cache')->delete();

        Schema::table('share_links', function (Blueprint $table) {
            $table->dropColumn(['content_key_ciphertext', 'key_protection', 'wrapped_key', 'wrap_salt']);
        });
    }

    public function down(): void
    {
        Schema::table('share_links', function (Blueprint $table) {
            $table->text('content_key_ciphertext')->nullable();
            $table->string('key_protection', 20)->default('fragment');
            $table->text('wrapped_key')->nullable();
            $table->string('wrap_salt')->nullable();
        });

        DB::statement(
            'ALTER TABLE share_links ADD CONSTRAINT share_links_key_protection_check '.
            "CHECK (key_protection IN ('fragment', 'passphrase'))"
        );
    }
};
