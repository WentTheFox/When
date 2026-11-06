<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * VRChat integration through a VRCX headless server (§0.2 tier throughout:
     * server-readable on purpose, APP_KEY-encrypted at rest).
     *
     * users.vrcx_base_url / vrcx_api_key: this owner's own VRCX server.
     * users.vrchat_user_id_definition_id: the identifier / identifier_list
     * field whose values are VRChat user ids (registered in FieldFeatures, so
     * the field can't be deleted while selected).
     *
     * connection_vrchat_profiles: one row per (connection, VRChat user id):
     * cached display name / picture and rolled-up time-together stats.
     * connection_vrchat_stays: one row per shared-instance interval. Kept
     * beyond VRCX's 90-day window so totals keep accumulating.
     *
     * The *_hash columns are keyed HMACs standing in for the encrypted
     * plaintext wherever a lookup or uniqueness check is needed (the same
     * trick as users.name_hash).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('vrcx_base_url')->nullable();
            $table->text('vrcx_api_key')->nullable();
            $table->timestampTz('vrcx_synced_at')->nullable();
            $table->foreignUuid('vrchat_user_id_definition_id')->nullable()
                ->constrained('connection_attribute_definitions')->nullOnDelete();
        });

        Schema::create('connection_vrchat_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('connection_id')->constrained()->cascadeOnDelete();
            $table->string('vrchat_user_id_hash', 64);
            $table->text('vrchat_user_id');
            $table->text('display_name')->nullable();
            $table->text('profile_picture_url')->nullable();
            $table->text('avatar_thumbnail_url')->nullable();
            $table->text('stats')->nullable();
            $table->timestampTz('profile_fetched_at')->nullable();
            $table->timestampsTz();

            $table->unique(['connection_id', 'vrchat_user_id_hash']);
        });

        Schema::create('connection_vrchat_stays', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('connection_id')->constrained()->cascadeOnDelete();
            $table->string('vrchat_user_id_hash', 64);
            $table->string('dedupe_hash', 64);
            $table->text('payload');
            $table->timestampsTz();

            $table->unique(['connection_id', 'dedupe_hash']);
            $table->index(['connection_id', 'vrchat_user_id_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connection_vrchat_stays');
        Schema::dropIfExists('connection_vrchat_profiles');

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vrchat_user_id_definition_id');
            $table->dropColumn(['vrcx_base_url', 'vrcx_api_key', 'vrcx_synced_at']);
        });
    }
};
