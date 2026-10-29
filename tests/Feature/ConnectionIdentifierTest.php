<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\ConnectionAttributeDefinition;
use App\Models\ConnectionAttributeValue;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** 'identifier' attribute values are §0.2-tier: plaintext over the wire, APP_KEY-encrypted at rest. */
class ConnectionIdentifierTest extends TestCase
{
    use RefreshDatabase;

    private function definition(User $user, string $type): ConnectionAttributeDefinition
    {
        return ConnectionAttributeDefinition::create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'label_ciphertext' => 'opaque',
            'type' => $type,
        ]);
    }

    private function connection(User $user): Connection
    {
        return Connection::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'name_ciphertext' => 'opaque']);
    }

    public function test_identifier_is_encrypted_at_rest_and_returned_decrypted(): void
    {
        $user = User::factory()->create();
        $definition = $this->definition($user, 'identifier');

        $response = $this->actingAs($user)->postJson('/dashboard/connections', [
            'id' => (string) Str::uuid(),
            'name_ciphertext' => 'opaque',
            'attribute_values' => [['attribute_definition_id' => $definition->id, 'identifier_value' => 'discord-12345']],
        ])->assertCreated();

        $response->assertJsonPath('attribute_values.0.identifier_value', 'discord-12345');
        $raw = DB::table('connection_attribute_values')->first();
        $this->assertNotSame('discord-12345', $raw->identifier_value);
        $this->assertStringNotContainsString('discord-12345', $raw->identifier_value);
        $this->assertNull($raw->value_ciphertext);
    }

    public function test_value_shape_must_match_definition_type(): void
    {
        $user = User::factory()->create();
        $identifier = $this->definition($user, 'identifier');
        $text = $this->definition($user, 'text');
        $connection = $this->connection($user);

        foreach ([
            ['attribute_definition_id' => $identifier->id, 'value_ciphertext' => 'opaque'],
            ['attribute_definition_id' => $text->id, 'identifier_value' => 'plain'],
        ] as $bad) {
            $this->actingAs($user)->patchJson("/dashboard/connections/{$connection->id}", ['attribute_values' => [$bad]])
                ->assertUnprocessable();
        }
    }

    public function test_fill_identifiers_only_fills_empty_and_is_idempotent(): void
    {
        $user = User::factory()->create();
        $identifier = $this->definition($user, 'identifier');
        $a = $this->connection($user);
        $b = $this->connection($user);
        ConnectionAttributeValue::create([
            'connection_id' => $b->id,
            'attribute_definition_id' => $identifier->id,
            'identifier_value' => 'keep-me',
        ]);

        $payload = ['values' => [
            ['connection_id' => $a->id, 'identifier_value' => 'new-a'],
            ['connection_id' => $b->id, 'identifier_value' => 'overwrite-b'],
        ]];
        $url = "/dashboard/connection-attribute-definitions/{$identifier->id}/fill-identifiers";

        $this->actingAs($user)->postJson($url, $payload)->assertOk()->assertExactJson(['filled' => [$a->id]]);
        $this->actingAs($user)->postJson($url, $payload)->assertOk()->assertExactJson(['filled' => []]);

        $this->assertSame('new-a', ConnectionAttributeValue::where('connection_id', $a->id)->first()->identifier_value);
        $this->assertSame('keep-me', ConnectionAttributeValue::where('connection_id', $b->id)->first()->identifier_value);
    }

    public function test_fill_identifiers_rejects_other_users_rows_and_non_identifier_targets(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $mine = $this->definition($owner, 'identifier');
        $theirs = $this->definition($stranger, 'identifier');
        $text = $this->definition($owner, 'text');
        $theirConnection = $this->connection($stranger);
        $myConnection = $this->connection($owner);
        $values = ['values' => [['connection_id' => $myConnection->id, 'identifier_value' => 'x']]];

        $this->actingAs($owner)->postJson("/dashboard/connection-attribute-definitions/{$theirs->id}/fill-identifiers", $values)->assertNotFound();
        $this->actingAs($owner)->postJson("/dashboard/connection-attribute-definitions/{$text->id}/fill-identifiers", $values)->assertUnprocessable();
        $this->actingAs($owner)->postJson("/dashboard/connection-attribute-definitions/{$mine->id}/fill-identifiers", [
            'values' => [['connection_id' => $theirConnection->id, 'identifier_value' => 'x']],
        ])->assertUnprocessable();
    }
}
