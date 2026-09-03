<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Renames share_links.legacy_token to highlight_token. "legacy_token" only
 * ever described where the value came from at import time (Stage 5's
 * migration from the old calendar_highlight_tokens system) — since every
 * new share link gets one at creation now too (see
 * App\Models\ShareLink::generateHighlightToken(), called from
 * ShareLinkManagementController::store()), "legacy" stopped describing the
 * column's actual role. highlight_token matches the old app's own column
 * name for the same public identifier.
 *
 * Also backfills a token onto every row that doesn't already have one
 * (every link created before this change) — public URL/content-key
 * derivation is moving from `highlight_token ?? id` to an unconditional
 * `highlight_token` (see App\Services\Crypto\HighlightTokenKey's callers),
 * so an existing link can't be left with a null token or it would stop
 * resolving. Same generation method as ShareLink::generateHighlightToken().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('share_links', function (Blueprint $table) {
            $table->renameColumn('legacy_token', 'highlight_token');
        });

        foreach (DB::table('share_links')->whereNull('highlight_token')->pluck('id') as $id) {
            DB::table('share_links')->where('id', $id)->update([
                'highlight_token' => $this->generateToken(),
            ]);
        }
    }

    private function generateToken(): string
    {
        do {
            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        } while (str_contains($token, '-') || str_contains($token, '_') || DB::table('share_links')->where('highlight_token', $token)->exists());

        return $token;
    }

    public function down(): void
    {
        Schema::table('share_links', function (Blueprint $table) {
            $table->renameColumn('highlight_token', 'legacy_token');
        });
    }
};
