<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();

            // Session login (§0.3) — independent of the vault key below.
            $table->string('password');

            // Vault key derivation (§0.3): Argon2id(passphrase, salt=this).
            // The passphrase and derived key are never sent to or stored by
            // the server — only the salt is.
            $table->string('passphrase_salt');

            // TOTP 2FA — orthogonal to the vault key, reused from the source app's flow.
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();

            // Calendar URL (§0.2): encrypted at rest with a runtime-only key
            // (Laravel Crypt / APP_KEY). Decrypted transiently, only inside
            // the ICS-fetch job. Never plaintext outside that job's memory.
            $table->text('calendar_url_ciphertext')->nullable();
            $table->string('calendar_parsing_mode', 20)->default('auto');

            $table->string('timezone')->default('UTC');
            $table->string('dnd_event_name')->nullable();
            $table->string('nap_event_name')->nullable();
            // Per-weekday wake/sleep windows. Schema shape (not user-authored
            // content) — plaintext per the framing note in PLAN.md §Stage 1.
            $table->json('availability_settings')->nullable();

            $table->rememberToken();
            $table->timestamps();

            $table->index('calendar_parsing_mode');
        });

        DB::statement(
            "ALTER TABLE users ADD CONSTRAINT users_calendar_parsing_mode_check ".
            "CHECK (calendar_parsing_mode IN ('full_detail', 'free_busy_only', 'auto'))"
        );

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignUuid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
