<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('share_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();

            // Owner's own label for the link (e.g. "for Mom") — client-side E2EE
            // per §0.1, same treatment as sleep_exceptions.label.
            $table->text('label_ciphertext')->nullable();

            // Key handling (§0.4). Default: the content key lives only in the URL
            // fragment, never touches the server. Optional upgrade: the owner
            // additionally wraps that key with an Argon2id(passphrase)-derived
            // key; the wrapped form is safe to store since it can't be unwrapped
            // without the passphrase, which the server never sees.
            $table->string('key_protection', 20)->default('fragment');
            $table->text('wrapped_key')->nullable();
            $table->string('wrap_salt')->nullable();

            $table->boolean('archived')->default(false);
            $table->boolean('bypass_dnd')->default(false);

            // Populated only for rows migrated from the old calendar_highlight_tokens
            // system (Stage 5) — the old token, so /free/{legacyToken} can keep
            // resolving and redirecting permanently.
            $table->string('legacy_token')->nullable()->unique();

            $table->timestamps();

            $table->index('user_id');
        });

        DB::statement(
            'ALTER TABLE share_links ADD CONSTRAINT share_links_key_protection_check '.
            "CHECK (key_protection IN ('fragment', 'passphrase'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('share_links');
    }
};
