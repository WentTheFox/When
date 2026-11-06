<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Carrying VRChat's private user notes over to a connection's notes.
     *
     * A connection's notes are end-to-end encrypted, so the server can't write them:
     * the browser does, when the owner presses "Import". The server only holds the
     * VRChat note until then.
     *
     * vrchat_note: the note VRCX reported, APP_KEY-encrypted (§0.2 tier), kept only
     * while it is waiting to be imported; cleared once the browser confirms.
     * note_imported_hash: keyed hash of the note text that was carried over, so the
     * same note is never offered (or appended) twice, while a note changed in VRChat
     * since is offered again.
     */
    public function up(): void
    {
        Schema::table('connection_vrchat_profiles', function (Blueprint $table) {
            $table->text('vrchat_note')->nullable()->after('discord_id');
            $table->string('note_imported_hash', 64)->nullable()->after('vrchat_note');
        });
    }

    public function down(): void
    {
        Schema::table('connection_vrchat_profiles', function (Blueprint $table) {
            $table->dropColumn(['vrchat_note', 'note_imported_hash']);
        });
    }
};
