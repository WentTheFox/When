<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** The Connections page sorts newly added connections to the top by when they were created, so every row has to say. */
class ConnectionCreatedAtTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_connections_page_gives_every_connection_its_creation_time(): void
    {
        $user = User::factory()->create();
        $connection = Connection::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'name_ciphertext' => 'opaque']);
        $connection->forceFill(['created_at' => '2026-11-05 10:30:00'])->save();

        $props = $this->actingAs($user)->get('/dashboard/connections')->assertOk()->viewData('page')['props'];

        $this->assertSame($connection->id, $props['connections'][0]['id']);
        $this->assertSame('2026-11-05T10:30:00+00:00', $props['connections'][0]['created_at']);
    }

    public function test_a_saved_connection_comes_back_with_its_creation_time_too(): void
    {
        $user = User::factory()->create();
        $id = (string) Str::uuid();

        $response = $this->actingAs($user)->postJson('/dashboard/connections', ['id' => $id, 'name_ciphertext' => 'opaque', 'source_ids' => [], 'attribute_values' => []])->assertCreated();

        $this->assertNotNull($response->json('created_at'));
        $this->assertEqualsWithDelta(now()->timestamp, strtotime($response->json('created_at')), 5);
    }
}
