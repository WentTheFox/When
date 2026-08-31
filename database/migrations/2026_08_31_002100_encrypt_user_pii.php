<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Encrypts users.name and users.email at rest (§0.2's server-runtime tier —
 * same Crypt/APP_KEY as calendar_url_ciphertext, not the client vault:
 * login has to work before a passphrase is ever entered, so this can't be
 * client-vault E2EE). Crypt's ciphertext is randomized per call, so the old
 * plain unique index on email can no longer do lookups or enforce
 * uniqueness — email_hash (a deterministic HMAC keyed on APP_KEY) replaces
 * it for both. See User::hashEmail() and the whereEmail() scope; every
 * `where('email', ...)` in the app had to move to `whereEmail(...)`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('name')->change();
            $table->text('email')->change();
            $table->string('email_hash')->nullable()->after('email');
        });

        foreach (DB::table('users')->select('id', 'name', 'email')->get() as $user) {
            DB::table('users')->where('id', $user->id)->update([
                'name' => Crypt::encryptString($user->name),
                'email' => Crypt::encryptString($user->email),
                'email_hash' => hash_hmac('sha256', mb_strtolower(trim($user->email)), config('app.key')),
            ]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['email']);
            $table->string('email_hash')->nullable(false)->change();
            $table->unique('email_hash');
        });
    }

    public function down(): void
    {
        foreach (DB::table('users')->select('id', 'name', 'email')->get() as $user) {
            DB::table('users')->where('id', $user->id)->update([
                'name' => Crypt::decryptString($user->name),
                'email' => Crypt::decryptString($user->email),
            ]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('email_hash');
            $table->string('name')->change();
            $table->string('email')->unique()->change();
        });
    }
};
