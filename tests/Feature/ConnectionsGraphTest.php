<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\ConnectionEdge;
use App\Models\ConnectionSource;
use App\Models\ConnectionSourceCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConnectionsGraphTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_graph_never_includes_another_users_nodes_or_edges(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $mine = Connection::create(['id' => Str::uuid(), 'user_id' => $owner->id, 'name_ciphertext' => 'x']);
        $theirs = Connection::create(['id' => Str::uuid(), 'user_id' => $other->id, 'name_ciphertext' => 'y']);

        $response = $this->actingAs($owner)->getJson('/dashboard/connections/graph');

        $response->assertOk();
        $ids = array_column($response->json('nodes'), 'id');
        $this->assertContains((string) $mine->id, $ids);
        $this->assertNotContains((string) $theirs->id, $ids);
    }

    public function test_a_source_node_carries_its_categorys_color_key(): void
    {
        $owner = User::factory()->create();

        $category = ConnectionSourceCategory::create([
            'id' => Str::uuid(),
            'user_id' => $owner->id,
            'name_ciphertext' => 'x',
            'color_key' => 'jade',
        ]);
        $source = ConnectionSource::create([
            'id' => Str::uuid(),
            'user_id' => $owner->id,
            'category_id' => $category->id,
            'name_ciphertext' => 'x',
        ]);
        $connection = Connection::create(['id' => Str::uuid(), 'user_id' => $owner->id, 'name_ciphertext' => 'x']);
        $connection->sources()->attach((string) $source->id);

        $response = $this->actingAs($owner)->getJson('/dashboard/connections/graph');

        $response->assertOk();
        $nodes = collect($response->json('nodes'));
        $sourceNode = $nodes->firstWhere('id', (string) $source->id);
        $this->assertSame('source', $sourceNode['type']);
        $this->assertSame('jade', $sourceNode['color_key']);

        $edges = collect($response->json('edges'));
        $introduced = $edges->firstWhere('kind', 'introduced');
        $this->assertSame((string) $connection->id, $introduced['from']);
        $this->assertSame((string) $source->id, $introduced['to']);
    }

    public function test_a_connection_to_connection_edge_has_no_directionality(): void
    {
        $owner = User::factory()->create();

        $a = Connection::create(['id' => Str::uuid(), 'user_id' => $owner->id, 'name_ciphertext' => 'x']);
        $b = Connection::create(['id' => Str::uuid(), 'user_id' => $owner->id, 'name_ciphertext' => 'y']);
        ConnectionEdge::create([
            'id' => Str::uuid(),
            'user_id' => $owner->id,
            'from_connection_id' => $a->id,
            'to_connection_id' => $b->id,
        ]);

        $response = $this->actingAs($owner)->getJson('/dashboard/connections/graph');

        $response->assertOk();
        $edges = collect($response->json('edges'));
        $this->assertSame('mutual', $edges->firstWhere('from', (string) $a->id)['kind']);
    }

    public function test_category_color_key_must_be_a_valid_palette_key(): void
    {
        $owner = User::factory()->create();

        $response = $this->actingAs($owner)->postJson('/dashboard/connection-source-categories', [
            'id' => (string) Str::uuid(),
            'name_ciphertext' => 'x',
            'color_key' => 'not-a-real-color',
        ]);

        $response->assertStatus(422);
    }

    public function test_category_color_key_can_be_saved_and_cleared(): void
    {
        $owner = User::factory()->create();
        $id = (string) Str::uuid();

        $this->actingAs($owner)->postJson('/dashboard/connection-source-categories', [
            'id' => $id,
            'name_ciphertext' => 'x',
            'color_key' => 'teal',
        ])->assertCreated();

        $this->assertDatabaseHas('connection_source_categories', ['id' => $id, 'color_key' => 'teal']);

        $this->actingAs($owner)->patchJson("/dashboard/connection-source-categories/{$id}", [
            'name_ciphertext' => 'x',
            'color_key' => null,
        ])->assertOk();

        $this->assertDatabaseHas('connection_source_categories', ['id' => $id, 'color_key' => null]);
    }
}
