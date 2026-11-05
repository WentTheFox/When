<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\ShareLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DropArchivedFromShareLinksMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_11_05_060000_drop_archived_from_share_links.php');
    }

    public function test_rolling_back_restores_the_column_filled_in_from_each_connection(): void
    {
        $user = User::factory()->create();
        $archived = ShareLink::factory()->for($user)->archived()->create();
        $active = ShareLink::factory()->for($user)->create();

        $this->assertFalse(Schema::hasColumn('share_links', 'archived'));

        $this->migration()->down();

        $this->assertTrue(Schema::hasColumn('share_links', 'archived'));
        $this->assertTrue((bool) DB::table('share_links')->where('id', $archived->id)->value('archived'));
        $this->assertFalse((bool) DB::table('share_links')->where('id', $active->id)->value('archived'));

        // And it can go forward again.
        $this->migration()->up();
        $this->assertFalse(Schema::hasColumn('share_links', 'archived'));
        $this->assertSame(2, ShareLink::count());
        $this->assertTrue(Connection::findOrFail($archived->connection_id)->archived);
    }
}
