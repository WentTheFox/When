<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moves sleep_exceptions.start_date/end_date into the §0.2 server-runtime
 * Crypt/APP_KEY tier too — same plain 'encrypted' cast as the pattern/
 * timezone/availability_windows columns (2026_09_03_120000,
 * 2026_09_03_130000). label_ciphertext on this same table is a different,
 * unrelated tier (§0.1 client-vault E2EE, see this table's own creation
 * migration) and is untouched here.
 *
 * The old `date` type can't hold ciphertext, so both columns widen to
 * `text`. The one real behavior change this forces: start_date/end_date
 * are no longer meaningfully DB-orderable (ciphertext is randomized per
 * call, so `ORDER BY start_date` would sort noise, not dates) — the one
 * call site that relied on that, SettingsController::edit()'s
 * ->orderBy('start_date'), moves to sorting the already-decrypted string
 * in PHP after fetch instead (ISO 'Y-m-d' strings still sort correctly
 * lexicographically). No DB-level WHERE/range filter on these columns
 * exists anywhere in the app (every consumer fetches a user's own rows by
 * user_id alone and range-checks in PHP via CarbonImmutable — see
 * AvailabilityService::isSuppressedByException()), so nothing else reads
 * through a broken index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sleep_exceptions', function (Blueprint $table) {
            $table->text('start_date')->change();
            $table->text('end_date')->change();
        });

        foreach (DB::table('sleep_exceptions')->select('id', 'start_date', 'end_date')->get() as $exception) {
            DB::table('sleep_exceptions')->where('id', $exception->id)->update([
                'start_date' => Crypt::encryptString($exception->start_date),
                'end_date' => Crypt::encryptString($exception->end_date),
            ]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('sleep_exceptions')->select('id', 'start_date', 'end_date')->get() as $exception) {
            DB::table('sleep_exceptions')->where('id', $exception->id)->update([
                'start_date' => Crypt::decryptString($exception->start_date),
                'end_date' => Crypt::decryptString($exception->end_date),
            ]);
        }

        Schema::table('sleep_exceptions', function (Blueprint $table) {
            $table->date('start_date')->change();
            $table->date('end_date')->change();
        });
    }
};
