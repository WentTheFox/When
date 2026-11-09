<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A custom field's label is E2EE (label_ciphertext, under a per-record key in the owner's key ring), so the
     * server cannot name a field it creates itself (VisitorFieldsBackfill). Such a field is created with a
     * plaintext `staged_label` and a null label_ciphertext; the browser encrypts the staged label the next time it
     * reads the field and writes label_ciphertext back, which clears staged_label. Only ever a fixed, generic name
     * chosen by the server (never anything about the owner or their connections), so nothing private sits in the
     * plaintext column.
     */
    public function up(): void
    {
        Schema::table('connection_attribute_definitions', function (Blueprint $table) {
            $table->string('staged_label')->nullable();
            $table->text('label_ciphertext')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('connection_attribute_definitions')->whereNull('label_ciphertext')->update(['label_ciphertext' => '']);

        Schema::table('connection_attribute_definitions', function (Blueprint $table) {
            $table->text('label_ciphertext')->nullable(false)->change();
            $table->dropColumn('staged_label');
        });
    }
};
