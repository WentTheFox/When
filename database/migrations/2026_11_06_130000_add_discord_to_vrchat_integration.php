<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Discord, learned through VRCX: its API reports a user's Discord id when VRChat
     * has ever shown one. §0.2 tier like the rest of the VRChat data.
     *
     * users.discord_user_id_definition_id: the identifier / identifier-list custom
     * field the owner picked for Discord user ids (registered in FieldFeatures, so it
     * can't be deleted while selected). nullOnDelete as for the other bindings.
     *
     * connection_vrchat_profiles.discord_id: the id VRCX last reported for that
     * account, APP_KEY-encrypted. Only ever replaced by another id, never cleared.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignUuid('discord_user_id_definition_id')->nullable()
                ->constrained('connection_attribute_definitions')->nullOnDelete();
        });

        Schema::table('connection_vrchat_profiles', function (Blueprint $table) {
            $table->text('discord_id')->nullable()->after('avatar_thumbnail_url');
        });
    }

    public function down(): void
    {
        Schema::table('connection_vrchat_profiles', function (Blueprint $table) {
            $table->dropColumn('discord_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('discord_user_id_definition_id');
        });
    }
};
