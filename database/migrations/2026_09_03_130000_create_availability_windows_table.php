<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Replaces users.availability_settings (a single per-user JSON blob keyed
 * 0(Sun)..6(Sat), each `{wake, sleep}`) with a proper one-row-per-weekday
 * table — the shape AvailabilityService::compute()'s own $weeklyAvailability
 * parameter already treats it as (an array keyed by weekday), just no
 * longer flattened into one opaque column.
 *
 * wake_time/sleep_time are §0.2 server-runtime Crypt/APP_KEY ciphertext —
 * same tier and plain 'encrypted' cast as the pattern/timezone columns
 * (2026_09_03_120000) — stored as `text`, not the native Postgres `time`
 * type, since ciphertext isn't a valid time literal; never queried by
 * value (only ever read a whole user's rows at a time via the
 * user_id+weekday unique index), so no *_hash/whereX() machinery needed.
 *
 * Always exactly 7 rows per user (one per weekday, wake_time/sleep_time
 * both nullable) rather than only rows for configured days — every reader
 * of the old JSON blob already treated a missing key the same as a
 * present-but-blank one (see AvailabilityService::dayWindow()), so keeping
 * that "every weekday has an explicit row" invariant here avoids
 * reintroducing that ambiguity at the new call sites
 * (User::weeklyAvailability()/setWeeklyAvailability()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('availability_windows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday'); // 0 (Sun) .. 6 (Sat), date-fns' own weekStartsOn convention.
            $table->text('wake_time')->nullable();
            $table->text('sleep_time')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'weekday']);
        });

        foreach (DB::table('users')->select('id', 'availability_settings')->get() as $user) {
            $weekly = $user->availability_settings === null ? [] : json_decode($user->availability_settings, true);

            $rows = [];
            for ($weekday = 0; $weekday <= 6; $weekday++) {
                $config = $weekly[$weekday] ?? $weekly[(string) $weekday] ?? [];
                $wake = $config['wake'] ?? null;
                $sleep = $config['sleep'] ?? null;
                $rows[] = [
                    'id' => (string) Str::uuid(),
                    'user_id' => $user->id,
                    'weekday' => $weekday,
                    'wake_time' => $wake === null || $wake === '' ? null : Crypt::encryptString($wake),
                    'sleep_time' => $sleep === null || $sleep === '' ? null : Crypt::encryptString($sleep),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            DB::table('availability_windows')->insert($rows);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('availability_settings');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('availability_settings')->nullable();
        });

        foreach (DB::table('users')->select('id')->get() as $user) {
            $weekly = [];
            $windows = DB::table('availability_windows')->where('user_id', $user->id)->orderBy('weekday')->get();
            foreach ($windows as $window) {
                $weekly[$window->weekday] = [
                    'wake' => $window->wake_time === null ? null : Crypt::decryptString($window->wake_time),
                    'sleep' => $window->sleep_time === null ? null : Crypt::decryptString($window->sleep_time),
                ];
            }
            DB::table('users')->where('id', $user->id)->update([
                'availability_settings' => json_encode($weekly),
            ]);
        }

        Schema::dropIfExists('availability_windows');
    }
};
