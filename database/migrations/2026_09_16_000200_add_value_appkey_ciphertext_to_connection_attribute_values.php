<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A value now carries ciphertext in exactly one of two tiers, decided
     * by its parent definition's (now immutable) is_e2ee flag:
     *   - value_ciphertext: client-vault E2EE (§0.1), unchanged, now
     *     nullable since a server-decrypted-tier row never populates it.
     *   - value_appkey_ciphertext: new, §0.2 tier — Crypt::encryptString/
     *     APP_KEY, same pattern as User::calendar_url_ciphertext. Written
     *     and read server-side only, in ConnectionController.
     */
    public function up(): void
    {
        Schema::table('connection_attribute_values', function (Blueprint $table) {
            $table->text('value_ciphertext')->nullable()->change();
            $table->text('value_appkey_ciphertext')->nullable()->after('value_ciphertext');
        });
    }

    public function down(): void
    {
        Schema::table('connection_attribute_values', function (Blueprint $table) {
            $table->dropColumn('value_appkey_ciphertext');
            $table->text('value_ciphertext')->nullable(false)->change();
        });
    }
};
