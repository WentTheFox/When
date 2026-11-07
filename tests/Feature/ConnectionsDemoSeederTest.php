<?php

namespace Tests\Feature;

use App\Models\ConnectionAttributeValue;
use App\Models\User;
use Database\Seeders\ConnectionsDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConnectionsDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_mirrored_relationship_fields_bound_to_the_graph_and_no_plaintext_edges(): void
    {
        $this->seed(ConnectionsDemoSeeder::class);

        $user = User::whereName(ConnectionsDemoSeeder::DEMO_NAME)->firstOrFail();
        $fields = $user->connectionAttributeDefinitions()->where('type', 'relationship')->get();

        $this->assertCount(3, $fields);
        $this->assertEqualsCanonicalizing($fields->pluck('id')->all(), $user->graphFields()->pluck('connection_attribute_definitions.id')->all());
        $this->assertSame(0, $user->connectionEdges()->count());
        $this->assertGreaterThan(0, ConnectionAttributeValue::whereIn('attribute_definition_id', $fields->pluck('id'))->count());
        // Only opaque ciphertext is stored for them.
        $this->assertSame(0, ConnectionAttributeValue::whereIn('attribute_definition_id', $fields->pluck('id'))->whereNull('value_ciphertext')->count());
    }
}
