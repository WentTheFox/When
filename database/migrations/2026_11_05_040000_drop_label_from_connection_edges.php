<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Contract step for 2026_11_05_030000_make_connection_edges_unique_per_pair.
     * Deploy only once that migration is live everywhere: nothing reads or
     * writes the label any more (none of the existing edges had one).
     */
    public function up(): void
    {
        Schema::table('connection_edges', function (Blueprint $table) {
            $table->dropColumn('label_ciphertext');
        });
    }

    /** Restores the empty column only. */
    public function down(): void
    {
        Schema::table('connection_edges', function (Blueprint $table) {
            $table->text('label_ciphertext')->nullable();
        });
    }
};
