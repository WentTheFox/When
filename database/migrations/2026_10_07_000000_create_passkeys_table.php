<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * WebAuthn credentials usable as a second factor in place of a TOTP
     * code. `credential_id` and `public_key` are public by design (the
     * private key never leaves the authenticator), so they're stored as-is;
     * `name` is the owner's own label and gets the same §0.2 server-runtime
     * `encrypted` cast as the rest of the account's free-text fields.
     */
    public function up(): void
    {
        Schema::create('passkeys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->text('name');
            $table->string('credential_id')->unique();
            $table->text('public_key');
            $table->unsignedBigInteger('sign_count')->default(0);
            $table->json('transports')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('passkeys');
    }
};
