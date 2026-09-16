<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * is_e2ee is set once, at creation, and never changed afterward — see
     * ConnectionAttributeDefinitionController::store()'s doc comment; there
     * is deliberately no update() route for this table, so "immutable after
     * creation" needs no extra enforcement here.
     *
     * purpose only applies to a definition with E2EE disabled (App\Support\
     * CustomFieldPurpose) — a value-format hint (e.g. "discord") the owner
     * picks at creation so a future visitor-facing flow can validate/match
     * against it. Left plaintext like `type` above it: it's schema shape,
     * not user-authored content.
     */
    public function up(): void
    {
        Schema::table('connection_attribute_definitions', function (Blueprint $table) {
            $table->boolean('is_e2ee')->default(true)->after('label_ciphertext');
            $table->string('purpose', 30)->nullable()->after('options_ciphertext');
        });

        DB::statement(
            'ALTER TABLE connection_attribute_definitions ADD CONSTRAINT '.
            'connection_attribute_definitions_purpose_check '.
            "CHECK (purpose IS NULL OR purpose IN ('discord', 'vrchat'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE connection_attribute_definitions DROP CONSTRAINT connection_attribute_definitions_purpose_check');

        Schema::table('connection_attribute_definitions', function (Blueprint $table) {
            $table->dropColumn(['is_e2ee', 'purpose']);
        });
    }
};
