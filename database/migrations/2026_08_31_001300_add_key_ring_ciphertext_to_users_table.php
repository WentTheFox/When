<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The client-held "key ring" (§0.3) containing per-connection and
     * per-share-link content keys, encrypted as a whole with the vault key.
     * Must persist server-side so it survives across sessions/devices — the
     * server only ever holds and returns this ciphertext, never the vault
     * key needed to open it.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('key_ring_ciphertext')->nullable()->after('passphrase_salt');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('key_ring_ciphertext');
        });
    }
};
