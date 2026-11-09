<?php

use App\Models\ConnectionAttributeDefinition;
use App\Support\VisitorFieldsBackfill;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Languages and time zones recorded from share-link visitors become values of 'language' / 'timezone' custom
     * fields on the owner's connections (see VisitorFieldsBackfill). The fields are created with a staged plaintext
     * label, because labels are E2EE; the browser encrypts it the next time it reads them.
     */
    public function up(): void
    {
        VisitorFieldsBackfill::forAllUsers();
    }

    /** Removes the fields this created (those still staged) with their values; a field the browser has since labelled is the owner's and stays. */
    public function down(): void
    {
        ConnectionAttributeDefinition::whereIn('type', ['language', 'timezone'])->whereNull('label_ciphertext')->whereNotNull('staged_label')->get()->each->forceDelete();
    }
};
