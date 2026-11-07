<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\ConnectionAttributeValue;
use App\Models\ConnectionEdge;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Relationships are an ordinary E2EE custom field now, mirrored on both connections by the browser.
 * The server only checks ownership, field type and that nobody changed the other side meanwhile.
 */
class ConnectionRelationshipFieldTest extends TestCase
{
    use RefreshDatabase;

    private function connection(User $user): Connection
    {
        return Connection::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'name_ciphertext' => 'opaque-name']);
    }

    private function field(User $user, string $type = 'relationship')
    {
        return $user->connectionAttributeDefinitions()->create(['id' => (string) Str::uuid(), 'label_ciphertext' => 'label', 'type' => $type, 'sort_order' => 1]);
    }

    public function test_a_save_writes_both_sides_of_a_relationship_in_one_request(): void
    {
        $user = User::factory()->create();
        [$a, $b] = [$this->connection($user), $this->connection($user)];
        $field = $this->field($user);

        $this->actingAs($user)->patchJson("/dashboard/connections/{$a->id}", [
            'attribute_values' => [['attribute_definition_id' => $field->id, 'value_ciphertext' => 'a-side']],
            'mirror_values' => [['connection_id' => $b->id, 'attribute_definition_id' => $field->id, 'value_ciphertext' => 'b-side', 'base_ciphertext' => null]],
        ])->assertOk();

        $this->assertSame('a-side', ConnectionAttributeValue::where('connection_id', $a->id)->value('value_ciphertext'));
        $this->assertSame('b-side', ConnectionAttributeValue::where('connection_id', $b->id)->value('value_ciphertext'));
    }

    public function test_a_mirror_value_of_null_removes_the_other_side(): void
    {
        $user = User::factory()->create();
        [$a, $b] = [$this->connection($user), $this->connection($user)];
        $field = $this->field($user);
        ConnectionAttributeValue::create(['connection_id' => $b->id, 'attribute_definition_id' => $field->id, 'value_ciphertext' => 'old']);

        $this->actingAs($user)->patchJson("/dashboard/connections/{$a->id}", [
            'mirror_values' => [['connection_id' => $b->id, 'attribute_definition_id' => $field->id, 'value_ciphertext' => null, 'base_ciphertext' => 'old']],
        ])->assertOk();

        $this->assertSame(0, ConnectionAttributeValue::where('connection_id', $b->id)->count());
    }

    public function test_a_changed_other_side_refuses_the_whole_save(): void
    {
        $user = User::factory()->create();
        [$a, $b] = [$this->connection($user), $this->connection($user)];
        $field = $this->field($user);
        ConnectionAttributeValue::create(['connection_id' => $b->id, 'attribute_definition_id' => $field->id, 'value_ciphertext' => 'changed-elsewhere']);

        $this->actingAs($user)->patchJson("/dashboard/connections/{$a->id}", [
            'attribute_values' => [['attribute_definition_id' => $field->id, 'value_ciphertext' => 'a-side']],
            'mirror_values' => [['connection_id' => $b->id, 'attribute_definition_id' => $field->id, 'value_ciphertext' => 'b-side', 'base_ciphertext' => 'stale']],
        ])->assertStatus(409);

        $this->assertSame(0, ConnectionAttributeValue::where('connection_id', $a->id)->count());
        $this->assertSame('changed-elsewhere', ConnectionAttributeValue::where('connection_id', $b->id)->value('value_ciphertext'));
    }

    public function test_only_relationship_fields_own_connections_and_other_connections_can_be_mirrored(): void
    {
        $user = User::factory()->create();
        $stranger = User::factory()->create();
        [$a, $b] = [$this->connection($user), $this->connection($user)];
        $theirs = $this->connection($stranger);
        $field = $this->field($user);
        $text = $this->field($user, 'text');

        $mirror = fn (string $connection, string $definition) => ['mirror_values' => [['connection_id' => $connection, 'attribute_definition_id' => $definition, 'value_ciphertext' => 'x', 'base_ciphertext' => null]]];

        $this->actingAs($user)->patchJson("/dashboard/connections/{$a->id}", $mirror($b->id, $text->id))->assertUnprocessable();
        $this->actingAs($user)->patchJson("/dashboard/connections/{$a->id}", $mirror($a->id, $field->id))->assertUnprocessable();
        $this->actingAs($user)->patchJson("/dashboard/connections/{$a->id}", $mirror($theirs->id, $field->id))->assertUnprocessable();

        $this->assertSame(0, ConnectionAttributeValue::count());
    }

    public function test_the_legacy_edges_move_into_a_new_relationships_field_and_are_retired(): void
    {
        $user = User::factory()->create();
        [$a, $b] = [$this->connection($user), $this->connection($user)];
        ConnectionEdge::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'from_connection_id' => $a->id, 'to_connection_id' => $b->id]);
        $id = (string) Str::uuid();

        $this->actingAs($user)->postJson('/dashboard/connections/relationships-field', [
            'id' => $id,
            'label_ciphertext' => 'label-blob',
            'values' => [
                ['connection_id' => $a->id, 'value_ciphertext' => 'a-list', 'base_ciphertext' => null],
                ['connection_id' => $b->id, 'value_ciphertext' => 'b-list', 'base_ciphertext' => null],
            ],
        ])->assertOk()->assertJsonPath('created', true)->assertJsonPath('definition.type', 'relationship');

        $this->assertSame($id, $user->refresh()->relationships_field_definition_id);
        $this->assertNull($user->relationships_migrated_at);
        $this->assertSame([$id], $user->graphFields()->pluck('connection_attribute_definitions.id')->all());

        $this->actingAs($user)->postJson('/dashboard/connections/relationships-field', ['finish' => true])->assertOk()->assertJsonPath('created', false);

        $this->assertNotNull($user->refresh()->relationships_migrated_at);
        $this->assertSame(0, $user->connectionEdges()->count());
        $this->assertSame('a-list', ConnectionAttributeValue::where('connection_id', $a->id)->value('value_ciphertext'));
    }

    public function test_a_stale_base_is_reported_as_a_conflict_and_blocks_finishing(): void
    {
        $user = User::factory()->create();
        $a = $this->connection($user);
        ConnectionEdge::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'from_connection_id' => $a->id, 'to_connection_id' => $this->connection($user)->id]);
        $payload = ['id' => (string) Str::uuid(), 'label_ciphertext' => 'l'];
        $this->actingAs($user)->postJson('/dashboard/connections/relationships-field', $payload)->assertOk();
        $field = $user->refresh()->relationships_field_definition_id;
        ConnectionAttributeValue::create(['connection_id' => $a->id, 'attribute_definition_id' => $field, 'value_ciphertext' => 'typed-meanwhile']);

        $this->actingAs($user)->postJson('/dashboard/connections/relationships-field', [
            'values' => [['connection_id' => $a->id, 'value_ciphertext' => 'merged', 'base_ciphertext' => null]],
            'finish' => true,
        ])->assertOk()->assertJsonPath("conflicts.{$a->id}", 'typed-meanwhile');

        $this->assertSame('typed-meanwhile', ConnectionAttributeValue::where('connection_id', $a->id)->value('value_ciphertext'));
        $this->assertNull($user->refresh()->relationships_migrated_at);
        $this->assertSame(1, $user->connectionEdges()->count());
    }

    public function test_the_legacy_edges_prop_is_empty_once_migrated(): void
    {
        $user = User::factory()->create();
        $a = $this->connection($user);
        ConnectionEdge::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'from_connection_id' => $a->id, 'to_connection_id' => $this->connection($user)->id]);

        $this->actingAs($user)->get('/dashboard/connections')->assertInertia(fn ($page) => $page->has('legacyEdges', 1));

        $user->forceFill(['relationships_migrated_at' => now()])->save();

        $this->actingAs($user)->get('/dashboard/connections')->assertInertia(fn ($page) => $page->has('legacyEdges', 0));
    }

    public function test_the_graph_fields_setting_only_accepts_own_relationship_or_connection_fields(): void
    {
        $user = User::factory()->create();
        $stranger = User::factory()->create();
        $mine = $this->field($user);
        $text = $this->field($user, 'text');
        $theirs = $this->field($stranger);

        $this->actingAs($user)->patch('/settings', ['graph_field_ids' => [$mine->id]])->assertSessionHasNoErrors();
        $this->assertSame([$mine->id], $user->graphFields()->pluck('connection_attribute_definitions.id')->all());

        $this->actingAs($user)->patch('/settings', ['graph_field_ids' => [$text->id]])->assertSessionHasErrors('graph_field_ids.0');
        $this->actingAs($user)->patch('/settings', ['graph_field_ids' => [$theirs->id]])->assertSessionHasErrors('graph_field_ids.0');

        $this->actingAs($user)->patch('/settings', ['graph_field_ids' => []])->assertSessionHasNoErrors();
        $this->assertSame(0, $user->graphFields()->count());
    }
}
