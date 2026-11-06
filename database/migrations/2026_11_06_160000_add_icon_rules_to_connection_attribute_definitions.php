<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-field icon rules: which icon (if any) a connection row shows based on
     * the value of this field. The whole rule list — conditions, regexes,
     * choice references, icon and colour keys — is one client-vault E2EE blob
     * (§0.1), encrypted with the definition's own record key like
     * options_ciphertext. The blob carries its own schema version inside the
     * ciphertext; the server never reads it.
     */
    public function up(): void
    {
        Schema::table('connection_attribute_definitions', function (Blueprint $table) {
            $table->text('icon_rules_ciphertext')->nullable()->after('options_ciphertext');
        });
    }

    public function down(): void
    {
        Schema::table('connection_attribute_definitions', function (Blueprint $table) {
            $table->dropColumn('icon_rules_ciphertext');
        });
    }
};
