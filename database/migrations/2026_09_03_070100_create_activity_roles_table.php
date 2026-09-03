<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Generalizes two previously-separate, narrower things into one
     * owner-configurable list:
     *
     * - HighlightMatcher's old hardcoded "Host X"/"Visit X" convention
     *   (a fixed English phrase, matched the same way as
     *   highlight_clause_pattern — a regex fragment requiring exactly one
     *   capture group for the name — see Regex::validateSingleCaptureGroup).
     * - ActivityExtractor's activity_clause_pattern, which only ever
     *   produces raw, unlocalized freetext (e.g. "Dinner") shown to a
     *   viewer as-is regardless of their own locale.
     *
     * Each row here is a (pattern, label) pair: `pattern` matched against
     * the event the same way a role pattern always was; `label` an
     * App\Support\LocalizedText — the localized text actually shown to
     * the viewer when this role's own pattern matches, instead of
     * whatever raw freetext activity_clause_pattern would otherwise
     * extract. activity_clause_pattern itself is untouched and still
     * used as the fallback when no configured role matches — this list
     * is for the activities an owner wants a clean, deliberately-chosen,
     * *localized* label for (including relationship roles like
     * hosting/visiting), not a replacement for free-text extraction.
     *
     * Backfills the two previously-hardcoded roles for every existing
     * user, in their old fixed order — otherwise this migration would be
     * a silent behavior regression: HighlightMatcher used to always check
     * Host/Visit unconditionally, and switching that to "only whatever
     * roles this owner has configured" would make every owner's existing
     * "Host X"/"Visit X" titles stop matching the instant this deploys,
     * with no warning. Newly-registered owners after this point start
     * with an empty list (feature off) like every other pattern field —
     * only current owners get the one-time backfill, since only they
     * could already be relying on the old hardcoded behavior. The label
     * shown for each is the *viewer's* own role, not the owner's — see
     * HighlightMatcher's own doc comment for the perspective-flip: an
     * owner's "Host Alice" title means Alice herself is visiting, so that
     * role's own label is "Visiting", not "Hosting". English-only seed
     * (no `hu` override) — deliberately not assuming every backfilled
     * owner wants a Hungarian translation baked in; add one label a role
     * still starts owner-editable either way, and a raw non-ASCII string
     * literal in a migration risks the exact portability problem this
     * comment is now warning about (hit locally against a SQL_ASCII-
     * encoded database: `json_encode()`'s \uXXXX escapes for á/é aren't
     * translatable when the server's own encoding isn't UTF-8).
     */
    public function up(): void
    {
        Schema::create('activity_roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('pattern');
            $table->jsonb('label');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $now = now();

        DB::table('users')->select('id')->orderBy('id')->chunkById(200, function ($users) use ($now) {
            $rows = [];
            foreach ($users as $user) {
                $rows[] = [
                    'id' => (string) Str::uuid(),
                    'user_id' => $user->id,
                    'pattern' => '^host\s+(.+)$',
                    'label' => json_encode(['default' => 'Visiting']),
                    'sort_order' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $rows[] = [
                    'id' => (string) Str::uuid(),
                    'user_id' => $user->id,
                    'pattern' => '^visit\s+(.+)$',
                    'label' => json_encode(['default' => 'Hosting']),
                    'sort_order' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            DB::table('activity_roles')->insert($rows);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_roles');
    }
};
