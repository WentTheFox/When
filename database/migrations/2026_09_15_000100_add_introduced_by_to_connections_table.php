<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            // Who introduced the owner to this connection — client-side E2EE
            // per §0.1, same treatment as notes_ciphertext.
            $table->text('introduced_by_ciphertext')->nullable()->after('notes_ciphertext');
        });
    }

    public function down(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->dropColumn('introduced_by_ciphertext');
        });
    }
};
