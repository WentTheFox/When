<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\ConnectionEdge;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConnectionEdgeTest extends TestCase
{
    use RefreshDatabase;

    private function pair(User $owner): array
    {
        return [Connection::factory()->for($owner)->create(), Connection::factory()->for($owner)->create()];
    }

    /** New relationships are E2EE field values; nothing may create server-readable edges any more. */
    public function test_the_plaintext_edge_endpoints_are_gone(): void
    {
        $owner = User::factory()->create();
        [$a, $b] = $this->pair($owner);

        $this->actingAs($owner)->postJson('/dashboard/connection-edges', ['from_connection_id' => $a->id, 'to_connection_id' => $b->id])->assertStatus(404);
        $this->actingAs($owner)->deleteJson('/dashboard/connection-edges/'.Str::uuid())->assertStatus(404);
    }

    public function test_the_database_still_rejects_a_mirrored_legacy_edge(): void
    {
        $owner = User::factory()->create();
        [$a, $b] = $this->pair($owner);
        ConnectionEdge::create(['user_id' => $owner->id, 'from_connection_id' => $a->id, 'to_connection_id' => $b->id]);

        $this->expectException(UniqueConstraintViolationException::class);
        ConnectionEdge::create(['user_id' => $owner->id, 'from_connection_id' => $b->id, 'to_connection_id' => $a->id]);
    }

    /** Mirrored pairs that piled up before the pair index existed are collapsed to the oldest edge. */
    public function test_the_migration_collapses_existing_mirrored_edges(): void
    {
        $owner = User::factory()->create();
        [$a, $b] = $this->pair($owner);
        $c = Connection::factory()->for($owner)->create();

        DB::statement('DROP INDEX connection_edges_pair_unique');

        $row = fn (string $id, Connection $from, Connection $to, string $createdAt) => DB::table('connection_edges')->insert([
            'id' => $id, 'user_id' => $owner->id,
            'from_connection_id' => $from->id, 'to_connection_id' => $to->id,
            'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]);
        $oldest = (string) Str::uuid();
        $row($oldest, $a, $b, '2026-01-01 00:00:00');
        $row((string) Str::uuid(), $b, $a, '2026-01-01 00:00:01'); // mirrored
        $solo = (string) Str::uuid();
        $row($solo, $a, $c, '2026-01-03 00:00:00'); // unrelated pair stays

        (require database_path('migrations/2026_11_05_030000_make_connection_edges_unique_per_pair.php'))->up();

        $this->assertEqualsCanonicalizing([$oldest, $solo], DB::table('connection_edges')->pluck('id')->all());
    }
}
