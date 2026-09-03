<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `name` doubles as the login identifier now (alongside optional
     * email) — no separate username column. It stays encrypted at rest
     * (case/punctuation preserved, exactly as before), but login/uniqueness
     * lookups need a deterministic key the same way whereEmail() does, so
     * `name_hash` (HMAC of the lowercased/trimmed name) is added the same
     * way email_hash already exists. See User::hashName()/scopeWhereName().
     *
     * Email used to be the sole login identifier, and the login-verifier
     * salt (resources/js/crypto/argon2.ts) was derived from it directly.
     * That breaks once email/name become editable, since the salt has to
     * be tied to something that never changes — the row's own uuid `id`.
     * `verifier_salt_version` records which scheme a given account's
     * stored password hash was salted with ('email' for every row that
     * predates this migration, 'id' for everything from here on); existing
     * users transparently re-salt to 'id' on their next successful login
     * (see AuthenticatedSessionController::store()).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('name_hash')->nullable()->after('name');
            $table->string('verifier_salt_version', 10)->default('email')->after('password');
        });

        Schema::table('users', function (Blueprint $table) {
            // text, not string — email was already text (see
            // 2026_08_31_002100_encrypt_user_pii.php) since Crypt
            // ciphertext can exceed 255 chars; only nullability changes
            // here, the column shouldn't shrink back to varchar(255).
            $table->text('email')->nullable()->change();
            $table->string('email_hash')->nullable()->change();
        });

        // Backfill name_hash for every pre-existing row. Unlike the old
        // auto-generated-username backfill this replaces, a collision here
        // can't be silently resolved by appending a suffix — that would
        // rename someone's actual display name — so a genuine duplicate
        // (two accounts with the literal same name, case/whitespace
        // aside) surfaces as a unique-constraint failure below and needs a
        // human to rename one of them first. Vanishingly unlikely for this
        // app's invite-only, small-userbase model.
        foreach (DB::table('users')->select('id', 'name')->get() as $row) {
            DB::table('users')->where('id', $row->id)->update([
                'name_hash' => User::hashName(Crypt::decryptString($row->name)),
            ]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('name_hash')->nullable(false)->unique()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['name_hash']);
            $table->dropColumn(['name_hash', 'verifier_salt_version']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->text('email')->nullable(false)->change();
            $table->string('email_hash')->nullable(false)->change();
        });
    }
};
