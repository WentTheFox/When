<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\ConnectionAttributeValue;
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

    public function test_connection_edges_are_never_computed_server_side_only_bound_field_ciphertext_is_handed_over(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $a = Connection::create(['id' => Str::uuid(), 'user_id' => $owner->id, 'name_ciphertext' => 'x']);
        $bound = $owner->connectionAttributeDefinitions()->create(['id' => (string) Str::uuid(), 'label_ciphertext' => 'l', 'type' => 'relationship', 'sort_order' => 1]);
        $unbound = $owner->connectionAttributeDefinitions()->create(['id' => (string) Str::uuid(), 'label_ciphertext' => 'l', 'type' => 'relationship', 'sort_order' => 2]);
        $owner->graphFields()->attach($bound->id);

        foreach ([$bound, $unbound] as $definition) {
            ConnectionAttributeValue::create([
                'connection_id' => $a->id,
                'attribute_definition_id' => $definition->id,
                'value_ciphertext' => 'blob-'.$definition->id,
            ]);
        }

        $response = $this->actingAs($owner)->getJson('/dashboard/connections/graph');

        $response->assertOk();
        $this->assertSame([], collect($response->json('edges'))->where('kind', 'mutual')->values()->all());
        $values = $response->json('field_values');
        $this->assertCount(1, $values);
        $this->assertSame('blob-'.$bound->id, $values[0]['value_ciphertext']);
        $this->assertSame('relationship', $values[0]['type']);
        $this->assertSame((string) $a->id, $values[0]['connection_id']);

        $this->assertSame([], $this->actingAs($other)->getJson('/dashboard/connections/graph')->json('field_values'));
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
