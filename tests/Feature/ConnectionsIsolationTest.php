<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\ConnectionAttributeDefinition;
use App\Models\ConnectionSource;
use App\Models\ConnectionSourceCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Stage 8: the plan explicitly calls out "connections CRUD isolation
 * across users" as a required regression test — this file is that test,
 * covering both directions: user A can never read/modify/delete user B's
 * rows via the dashboard API, and (the real bug this test caught) user A
 * can never *attach* their own connection to user B's source/share-link/
 * attribute-definition by simply passing that id — see
 * ConnectionController::validateConnection's doc comment for the fix.
 */
class ConnectionsIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_users_connections_index_never_includes_another_users_rows(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();

        Connection::create([
            'id' => (string) Str::uuid(),
            'user_id' => $stranger->id,
            'name_ciphertext' => 'opaque',
        ]);

        $response = $this->actingAs($owner)->get('/dashboard/connections');

        $response->assertInertia(fn ($page) => $page->where('connections', []));
    }

    public function test_cannot_update_another_users_connection(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $theirConnection = Connection::create([
            'id' => (string) Str::uuid(),
            'user_id' => $stranger->id,
            'name_ciphertext' => 'opaque',
        ]);

        $this->actingAs($owner)
            ->patchJson("/dashboard/connections/{$theirConnection->id}", ['name_ciphertext' => 'hijacked'])
            ->assertNotFound();

        $this->assertSame('opaque', $theirConnection->fresh()->name_ciphertext);
    }

    public function test_cannot_delete_another_users_connection(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $theirConnection = Connection::create([
            'id' => (string) Str::uuid(),
            'user_id' => $stranger->id,
            'name_ciphertext' => 'opaque',
        ]);

        $this->actingAs($owner)
            ->deleteJson("/dashboard/connections/{$theirConnection->id}")
            ->assertNotFound();

        $this->assertNotNull($theirConnection->fresh());
    }

    /** The actual bug: source_ids was validated with a bare exists:, no ownership scope. */
    public function test_cannot_attach_own_connection_to_another_users_source(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $theirSource = ConnectionSource::create([
            'id' => (string) Str::uuid(),
            'user_id' => $stranger->id,
            'name_ciphertext' => 'opaque',
        ]);

        $this->actingAs($owner)->postJson('/dashboard/connections', [
            'id' => (string) Str::uuid(),
            'name_ciphertext' => 'mine',
            'source_ids' => [$theirSource->id],
        ])->assertUnprocessable();
    }

    public function test_cannot_attach_an_attribute_value_to_another_users_definition(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $theirDefinition = ConnectionAttributeDefinition::create([
            'id' => (string) Str::uuid(),
            'user_id' => $stranger->id,
            'label_ciphertext' => 'opaque',
            'type' => 'text',
        ]);

        $this->actingAs($owner)->postJson('/dashboard/connections', [
            'id' => (string) Str::uuid(),
            'name_ciphertext' => 'mine',
            'attribute_values' => [
                ['attribute_definition_id' => $theirDefinition->id, 'value_ciphertext' => 'opaque'],
            ],
        ])->assertUnprocessable();
    }

    public function test_cannot_attach_own_source_to_another_users_category_on_create(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $theirCategory = ConnectionSourceCategory::create([
            'id' => (string) Str::uuid(),
            'user_id' => $stranger->id,
            'name_ciphertext' => 'opaque',
        ]);

        $this->actingAs($owner)->postJson('/dashboard/connection-sources', [
            'id' => (string) Str::uuid(),
            'name_ciphertext' => 'mine',
            'category_id' => $theirCategory->id,
        ])->assertUnprocessable();
    }

    public function test_cannot_attach_own_source_to_another_users_category_on_update(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $mySource = ConnectionSource::create(['id' => (string) Str::uuid(), 'user_id' => $owner->id, 'name_ciphertext' => 'mine']);
        $theirCategory = ConnectionSourceCategory::create([
            'id' => (string) Str::uuid(),
            'user_id' => $stranger->id,
            'name_ciphertext' => 'opaque',
        ]);

        $this->actingAs($owner)->patchJson("/dashboard/connection-sources/{$mySource->id}", [
            'name_ciphertext' => 'mine',
            'category_id' => $theirCategory->id,
        ])->assertUnprocessable();
    }

    public function test_cannot_update_another_users_source(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $theirSource = ConnectionSource::create([
            'id' => (string) Str::uuid(),
            'user_id' => $stranger->id,
            'name_ciphertext' => 'opaque',
        ]);

        $this->actingAs($owner)
            ->patchJson("/dashboard/connection-sources/{$theirSource->id}", ['name_ciphertext' => 'hijacked'])
            ->assertNotFound();
    }

    public function test_cannot_delete_another_users_source(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $theirSource = ConnectionSource::create([
            'id' => (string) Str::uuid(),
            'user_id' => $stranger->id,
            'name_ciphertext' => 'opaque',
        ]);

        $this->actingAs($owner)
            ->deleteJson("/dashboard/connection-sources/{$theirSource->id}")
            ->assertNotFound();
    }

    public function test_cannot_update_or_delete_another_users_source_category(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $theirCategory = ConnectionSourceCategory::create([
            'id' => (string) Str::uuid(),
            'user_id' => $stranger->id,
            'name_ciphertext' => 'opaque',
        ]);

        $this->actingAs($owner)
            ->patchJson("/dashboard/connection-source-categories/{$theirCategory->id}", ['name_ciphertext' => 'hijacked'])
            ->assertNotFound();

        $this->actingAs($owner)
            ->deleteJson("/dashboard/connection-source-categories/{$theirCategory->id}")
            ->assertNotFound();
    }

    public function test_cannot_delete_another_users_attribute_definition(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $theirDefinition = ConnectionAttributeDefinition::create([
            'id' => (string) Str::uuid(),
            'user_id' => $stranger->id,
            'label_ciphertext' => 'opaque',
            'type' => 'text',
        ]);

        $this->actingAs($owner)
            ->deleteJson("/dashboard/connection-attribute-definitions/{$theirDefinition->id}")
            ->assertNotFound();
    }

    public function test_cannot_mirror_a_relationship_into_another_users_connection(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $mine = Connection::create(['id' => (string) Str::uuid(), 'user_id' => $owner->id, 'name_ciphertext' => 'a']);
        $theirs = Connection::create(['id' => (string) Str::uuid(), 'user_id' => $stranger->id, 'name_ciphertext' => 'b']);
        $field = ConnectionAttributeDefinition::create(['id' => (string) Str::uuid(), 'user_id' => $owner->id, 'label_ciphertext' => 'a', 'type' => 'relationship']);

        $this->actingAs($owner)->patchJson("/dashboard/connections/{$mine->id}", [
            'mirror_values' => [['connection_id' => $theirs->id, 'attribute_definition_id' => $field->id, 'value_ciphertext' => 'x', 'base_ciphertext' => null]],
        ])->assertUnprocessable();

        $this->assertDatabaseCount('connection_attribute_values', 0);
    }

    public function test_cannot_mirror_with_another_users_relationship_field(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $a = Connection::create(['id' => (string) Str::uuid(), 'user_id' => $owner->id, 'name_ciphertext' => 'a']);
        $b = Connection::create(['id' => (string) Str::uuid(), 'user_id' => $owner->id, 'name_ciphertext' => 'b']);
        $theirField = ConnectionAttributeDefinition::create(['id' => (string) Str::uuid(), 'user_id' => $stranger->id, 'label_ciphertext' => 'a', 'type' => 'relationship']);

        $this->actingAs($owner)->patchJson("/dashboard/connections/{$a->id}", [
            'mirror_values' => [['connection_id' => $b->id, 'attribute_definition_id' => $theirField->id, 'value_ciphertext' => 'x', 'base_ciphertext' => null]],
        ])->assertUnprocessable();
    }

    public function test_cannot_rewrite_values_of_another_users_relationship_field(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $theirField = ConnectionAttributeDefinition::create(['id' => (string) Str::uuid(), 'user_id' => $stranger->id, 'label_ciphertext' => 'a', 'type' => 'relationship']);

        $this->actingAs($owner)
            ->postJson("/dashboard/connection-attribute-definitions/{$theirField->id}/rewrite-values", ['values' => []])
            ->assertNotFound();
    }

    /** The happy path still works — isolation shouldn't block an owner's own valid references. */
    public function test_a_connection_can_still_be_linked_to_the_owners_own_source_and_definition(): void
    {
        $owner = User::factory()->create();
        $mySource = ConnectionSource::create(['id' => (string) Str::uuid(), 'user_id' => $owner->id, 'name_ciphertext' => 'a']);
        $myDefinition = ConnectionAttributeDefinition::create([
            'id' => (string) Str::uuid(),
            'user_id' => $owner->id,
            'label_ciphertext' => 'a',
            'type' => 'text',
        ]);

        $this->actingAs($owner)->postJson('/dashboard/connections', [
            'id' => (string) Str::uuid(),
            'name_ciphertext' => 'mine',
            'source_ids' => [$mySource->id],
            'attribute_values' => [
                ['attribute_definition_id' => $myDefinition->id, 'value_ciphertext' => 'opaque'],
            ],
        ])->assertCreated();
    }
}
