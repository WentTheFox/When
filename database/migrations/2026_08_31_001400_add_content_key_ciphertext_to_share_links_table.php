<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §0.2/§0.3's recompute job needs to encrypt the freshly computed
     * free/busy result with the share link's own content key — but that key
     * lives in the URL fragment, which browsers never send anywhere. So the
     * OWNER's link-creation request (a normal POST, not the viewer's
     * fragment-carrying GET) sends the raw key to the server once; it's
     * stored here encrypted with the runtime-only key (same tier as
     * calendar_url — §0.2's documented "not protected against a compromised
     * runtime" trade-off applies here too), and decrypted only transiently
     * inside the recompute job.
     */
    public function up(): void
    {
        Schema::table('share_links', function (Blueprint $table) {
            $table->text('content_key_ciphertext')->nullable()->after('wrap_salt');
        });
    }

    public function down(): void
    {
        Schema::table('share_links', function (Blueprint $table) {
            $table->dropColumn('content_key_ciphertext');
        });
    }
};
