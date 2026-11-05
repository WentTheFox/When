<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\ShareLink;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Links archived in their own right must not come back to life when the flag
 * moves to the connection. (The column is gone in the current schema, so the
 * test puts it back, as it was when the migration had to run.)
 */
class CarryShareLinkArchivedMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_link_archived_on_its_own_archives_its_connection(): void
    {
        Schema::table('share_links', function (Blueprint $table) {
            $table->boolean('archived')->default(false);
        });

        $user = User::factory()->create();
        $archivedLink = ShareLink::factory()->for($user)->create();
        $activeLink = ShareLink::factory()->for($user)->create();
        $alreadyArchived = ShareLink::factory()->for($user)->archived()->create();

        DB::table('share_links')->where('id', $archivedLink->id)->update(['archived' => true]);
        DB::table('share_links')->where('id', $alreadyArchived->id)->update(['archived' => true]);

        $this->assertFalse($archivedLink->fresh()->isArchived(), 'it would be live without the migration');

        (require database_path('migrations/2026_11_05_055000_carry_share_link_archived_onto_connections.php'))->up();

        $this->assertTrue($archivedLink->fresh()->isArchived());
        $this->assertTrue(Connection::findOrFail($archivedLink->connection_id)->archived);
        $this->assertFalse($activeLink->fresh()->isArchived(), 'an active link stays active');
        $this->assertTrue($alreadyArchived->fresh()->isArchived());
    }

    public function test_running_it_again_changes_nothing(): void
    {
        Schema::table('share_links', function (Blueprint $table) {
            $table->boolean('archived')->default(false);
        });

        $link = ShareLink::factory()->for(User::factory()->create())->create();
        DB::table('share_links')->where('id', $link->id)->update(['archived' => true]);

        $migration = require database_path('migrations/2026_11_05_055000_carry_share_link_archived_onto_connections.php');
        $migration->up();
        $migration->up();

        $this->assertTrue($link->fresh()->isArchived());
        $this->assertSame(1, Connection::where('archived', true)->count());
    }
}
