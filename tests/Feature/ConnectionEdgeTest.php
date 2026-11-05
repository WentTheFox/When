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

    public function test_creating_an_edge_returns_its_id_and_endpoints(): void
    {
        $owner = User::factory()->create();
        [$a, $b] = $this->pair($owner);

        $response = $this->actingAs($owner)->postJson('/dashboard/connection-edges', [
            'from_connection_id' => $a->id,
            'to_connection_id' => $b->id,
        ]);

        $response->assertCreated()->assertJson(['from_connection_id' => $a->id, 'to_connection_id' => $b->id]);
        $this->assertSame(1, ConnectionEdge::count());
    }

    public function test_adding_the_same_edge_again_returns_the_existing_one(): void
    {
        $owner = User::factory()->create();
        [$a, $b] = $this->pair($owner);
        $payload = ['from_connection_id' => $a->id, 'to_connection_id' => $b->id];

        $first = $this->actingAs($owner)->postJson('/dashboard/connection-edges', $payload);
        $second = $this->actingAs($owner)->postJson('/dashboard/connection-edges', $payload);

        $second->assertOk();
        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertSame(1, ConnectionEdge::count());
    }

    public function test_adding_the_reverse_edge_returns_the_existing_one(): void
    {
        $owner = User::factory()->create();
        [$a, $b] = $this->pair($owner);

        $first = $this->actingAs($owner)->postJson('/dashboard/connection-edges', [
            'from_connection_id' => $a->id,
            'to_connection_id' => $b->id,
        ]);
        $reverse = $this->actingAs($owner)->postJson('/dashboard/connection-edges', [
            'from_connection_id' => $b->id,
            'to_connection_id' => $a->id,
        ]);

        $reverse->assertOk();
        $this->assertSame($first->json('id'), $reverse->json('id'));
        $this->assertSame(1, ConnectionEdge::count());
    }

    public function test_the_database_rejects_a_mirrored_edge_even_without_the_controller(): void
    {
        $owner = User::factory()->create();
        [$a, $b] = $this->pair($owner);
        ConnectionEdge::create(['user_id' => $owner->id, 'from_connection_id' => $a->id, 'to_connection_id' => $b->id]);

        $this->expectException(UniqueConstraintViolationException::class);
        ConnectionEdge::create(['user_id' => $owner->id, 'from_connection_id' => $b->id, 'to_connection_id' => $a->id]);
    }

    public function test_an_edge_can_be_recreated_after_it_is_deleted(): void
    {
        $owner = User::factory()->create();
        [$a, $b] = $this->pair($owner);
        $payload = ['from_connection_id' => $a->id, 'to_connection_id' => $b->id];

        $id = $this->actingAs($owner)->postJson('/dashboard/connection-edges', $payload)->json('id');
        $this->actingAs($owner)->deleteJson("/dashboard/connection-edges/{$id}")->assertOk();

        $this->actingAs($owner)->postJson('/dashboard/connection-edges', $payload)->assertCreated();
    }

    public function test_a_connection_cannot_know_itself(): void
    {
        $owner = User::factory()->create();
        [$a] = $this->pair($owner);

        $this->actingAs($owner)->postJson('/dashboard/connection-edges', [
            'from_connection_id' => $a->id,
            'to_connection_id' => $a->id,
        ])->assertUnprocessable();
    }

    public function test_a_connection_can_know_several_different_connections(): void
    {
        $owner = User::factory()->create();
        [$a, $b] = $this->pair($owner);
        $c = Connection::factory()->for($owner)->create();

        foreach ([$b, $c] as $other) {
            $this->actingAs($owner)->postJson('/dashboard/connection-edges', [
                'from_connection_id' => $a->id,
                'to_connection_id' => $other->id,
            ])->assertCreated();
        }

        $this->assertSame(2, ConnectionEdge::count());
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
