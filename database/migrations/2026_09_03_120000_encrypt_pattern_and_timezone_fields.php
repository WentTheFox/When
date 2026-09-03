<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moves users.timezone and every users.*_pattern column (plus
 * activity_localizations.pattern) into the §0.2 server-runtime Crypt/
 * APP_KEY tier — same as calendar_url_ciphertext/name/email, not client-
 * vault E2EE. Unlike name/email (see 2026_08_31_002100_encrypt_user_pii),
 * none of these fields is ever looked up by value anywhere in the app (no
 * `where('dnd_event_pattern', ...)`, no `where('timezone', ...)`), so
 * there's no need for a companion *_hash column or whereX() scope — a
 * plain Eloquent 'encrypted' cast (see User::casts()/ActivityLocalization::
 * casts()) is enough, transparently decrypting on every existing read site.
 *
 * Also adds a nullable *_preview sibling column next to each pattern
 * column — persists an owner's own edited example text in
 * resources/js/dashboard/PatternPreview.vue's tester (previously pure
 * local component state, lost on every reload) the same optional-override
 * way the pattern itself already works: null means "use the hardcoded
 * example set", a stored value means the owner edited it away from that.
 * Encrypted the same as its pattern, since it's the same tier of owner-
 * authored text.
 */
return new class extends Migration
{
    /** Columns already `text` before this migration — encrypted in place, no widen needed. */
    private const ALREADY_TEXT_PATTERN_COLUMNS = [
        'highlight_clause_pattern',
        'activity_clause_pattern',
        'tentative_pattern',
        'open_end_pattern',
        'open_start_pattern',
    ];

    /** Columns that need widening from string (varchar 255) to text — ciphertext runs well past 255 chars. */
    private const STRING_PATTERN_COLUMNS = [
        'dnd_event_pattern',
        'nap_event_pattern',
        'work_event_pattern',
        'school_event_pattern',
        'highlight_split_pattern',
    ];

    /** @return list<string> Every users.*_pattern column, widened or not. */
    private static function allPatternColumns(): array
    {
        return [...self::STRING_PATTERN_COLUMNS, ...self::ALREADY_TEXT_PATTERN_COLUMNS];
    }

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // No DB-level ->default('UTC')/NOT NULL any more, and
            // deliberately nullable now, not just widened: a raw SQL
            // default is applied straight into the ciphertext column by
            // Postgres whenever an insert omits the key entirely,
            // bypassing the 'encrypted' cast's mutator entirely (nothing to
            // decrypt on the next read) — so timezone now genuinely starts
            // out null (an owner who's never saved Settings has no
            // configured timezone at all) rather than silently defaulting
            // to 'UTC' either in the DB or in PHP. The frontend attempts to
            // autodetect the browser's own IANA zone on first load of the
            // Settings page (see SettingsCalendarCard.vue) so it's usually
            // already filled in by the time of the owner's first save
            // regardless; every server-side reader of a possibly-still-null
            // timezone falls back to 'UTC' itself at the point of use (see
            // DashboardController, RecomputeShareLinkAvailability,
            // ShareLinkAvailabilityController) rather than the column ever
            // being forced non-null.
            $table->text('timezone')->nullable()->change();
            foreach (self::STRING_PATTERN_COLUMNS as $column) {
                $table->text($column)->nullable()->change();
            }
            foreach (self::allPatternColumns() as $column) {
                $table->text("{$column}_preview")->nullable()->after($column);
            }
        });

        foreach (DB::table('users')->select('id', 'timezone', ...self::allPatternColumns())->get() as $user) {
            // timezone was NOT NULL with a DB default of 'UTC' before this
            // migration, so every existing row already has a real string
            // value here — still null-safe, matching every *_pattern
            // column, since the column becomes genuinely nullable below.
            $update = ['timezone' => $user->timezone === null ? null : Crypt::encryptString($user->timezone)];
            foreach (self::allPatternColumns() as $column) {
                $update[$column] = $user->$column === null ? null : Crypt::encryptString($user->$column);
            }
            DB::table('users')->where('id', $user->id)->update($update);
        }

        Schema::table('activity_localizations', function (Blueprint $table) {
            $table->text('pattern')->change();
            $table->text('pattern_preview')->nullable()->after('pattern');
        });

        foreach (DB::table('activity_localizations')->select('id', 'pattern')->get() as $role) {
            DB::table('activity_localizations')->where('id', $role->id)->update([
                'pattern' => Crypt::encryptString($role->pattern),
            ]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('users')->select('id', 'timezone', ...self::allPatternColumns())->get() as $user) {
            // A user who saved Settings after this migration but before its
            // rollback can genuinely have a null timezone (see up()'s own
            // comment) — the column's about to go back to NOT NULL below,
            // which can't hold that, so it backfills to 'UTC' here rather
            // than leaving a row this rollback can't actually produce.
            $update = ['timezone' => $user->timezone === null ? 'UTC' : Crypt::decryptString($user->timezone)];
            foreach (self::allPatternColumns() as $column) {
                $update[$column] = $user->$column === null ? null : Crypt::decryptString($user->$column);
            }
            DB::table('users')->where('id', $user->id)->update($update);
        }

        Schema::table('users', function (Blueprint $table) {
            foreach (self::allPatternColumns() as $column) {
                $table->dropColumn("{$column}_preview");
            }
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('timezone')->default('UTC')->nullable(false)->change();
            foreach (self::STRING_PATTERN_COLUMNS as $column) {
                $table->string($column)->nullable()->change();
            }
        });

        foreach (DB::table('activity_localizations')->select('id', 'pattern')->get() as $role) {
            DB::table('activity_localizations')->where('id', $role->id)->update([
                'pattern' => Crypt::decryptString($role->pattern),
            ]);
        }

        Schema::table('activity_localizations', function (Blueprint $table) {
            $table->dropColumn('pattern_preview');
            $table->string('pattern')->change();
        });
    }
};
