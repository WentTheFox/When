<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\ConnectionAttributeDefinition;
use App\Models\ConnectionAttributeValue;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A connection attribute definition can opt a field's *value* out of
 * client-vault E2EE (§0.1) into the server-runtime tier (§0.2, Crypt/
 * APP_KEY) at creation time, with an optional "purpose" (App\Support\
 * CustomFieldPurpose) driving extra format validation on the value. See
 * ConnectionAttributeDefinitionController::store() and
 * ConnectionController::syncAttributeValues().
 */
class ConnectionAttributeE2eeToggleTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_definition_can_be_created_with_e2ee_disabled_and_a_purpose(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/dashboard/connection-attribute-definitions', [
            'id' => (string) Str::uuid(),
            'label_ciphertext' => 'opaque-label',
            'type' => 'text',
            'is_e2ee' => false,
            'purpose' => 'discord',
        ]);

        $response->assertCreated();

        $definition = ConnectionAttributeDefinition::find($response->json('id'));
        $this->assertFalse($definition->is_e2ee);
        $this->assertSame('discord', $definition->purpose);
    }

    public function test_a_definition_defaults_to_e2ee_enabled_when_not_specified(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/dashboard/connection-attribute-definitions', [
            'id' => (string) Str::uuid(),
            'label_ciphertext' => 'opaque-label',
            'type' => 'text',
        ]);

        $response->assertCreated();
        $this->assertTrue(ConnectionAttributeDefinition::find($response->json('id'))->is_e2ee);
    }

    public function test_a_purpose_cannot_be_set_when_e2ee_is_left_enabled(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/dashboard/connection-attribute-definitions', [
            'id' => (string) Str::uuid(),
            'label_ciphertext' => 'opaque-label',
            'type' => 'text',
            'is_e2ee' => true,
            'purpose' => 'discord',
        ])->assertUnprocessable();
    }

    public function test_an_unrecognized_purpose_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/dashboard/connection-attribute-definitions', [
            'id' => (string) Str::uuid(),
            'label_ciphertext' => 'opaque-label',
            'type' => 'text',
            'is_e2ee' => false,
            'purpose' => 'not-a-real-purpose',
        ])->assertUnprocessable();
    }

    /**
     * There is deliberately no PATCH/update route for this resource — the
     * only way to change is_e2ee/purpose is delete-and-recreate, which
     * necessarily loses any existing values under the old definition.
     */
    public function test_there_is_no_route_to_update_a_definition_after_creation(): void
    {
        $user = User::factory()->create();
        $definition = ConnectionAttributeDefinition::create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'label_ciphertext' => 'opaque-label',
            'type' => 'text',
            'is_e2ee' => true,
        ]);

        // 405, not 404 — the {definition} URI is claimed by the DELETE
        // route, so PATCH correctly resolves to "method not allowed" rather
        // than "no such route".
        $this->actingAs($user)
            ->patchJson("/dashboard/connection-attribute-definitions/{$definition->id}", ['is_e2ee' => false])
            ->assertStatus(405);

        $this->assertTrue($definition->fresh()->is_e2ee);
    }

    public function test_a_value_for_a_non_e2ee_definition_is_stored_app_key_encrypted_and_returned_as_plaintext_to_the_owner(): void
    {
        $user = User::factory()->create();
        $definition = ConnectionAttributeDefinition::create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'label_ciphertext' => 'opaque-label',
            'type' => 'text',
            'is_e2ee' => false,
            'purpose' => 'discord',
        ]);

        $response = $this->actingAs($user)->postJson('/dashboard/connections', [
            'id' => (string) Str::uuid(),
            'name_ciphertext' => 'opaque-name',
            'attribute_values' => [
                ['attribute_definition_id' => $definition->id, 'value' => 'some.user'],
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonFragment(['attribute_definition_id' => $definition->id, 'value' => 'some.user']);

        $stored = ConnectionAttributeValue::where('attribute_definition_id', $definition->id)->firstOrFail();
        $this->assertNull($stored->value_ciphertext);
        $this->assertNotNull($stored->value_appkey_ciphertext);
        $this->assertNotSame('some.user', $stored->value_appkey_ciphertext);
        $this->assertSame('some.user', Crypt::decryptString($stored->value_appkey_ciphertext));
    }

    public function test_a_discord_purpose_value_must_match_the_username_format(): void
    {
        $user = User::factory()->create();
        $definition = ConnectionAttributeDefinition::create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'label_ciphertext' => 'opaque-label',
            'type' => 'text',
            'is_e2ee' => false,
            'purpose' => 'discord',
        ]);

        $this->actingAs($user)->postJson('/dashboard/connections', [
            'id' => (string) Str::uuid(),
            'name_ciphertext' => 'opaque-name',
            'attribute_values' => [
                ['attribute_definition_id' => $definition->id, 'value' => 'Not A Valid Discord Name!'],
            ],
        ])->assertUnprocessable();
    }

    public function test_a_value_for_an_e2ee_definition_still_requires_value_ciphertext_not_value(): void
    {
        $user = User::factory()->create();
        $definition = ConnectionAttributeDefinition::create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'label_ciphertext' => 'opaque-label',
            'type' => 'text',
            'is_e2ee' => true,
        ]);

        $this->actingAs($user)->postJson('/dashboard/connections', [
            'id' => (string) Str::uuid(),
            'name_ciphertext' => 'opaque-name',
            'attribute_values' => [
                ['attribute_definition_id' => $definition->id, 'value' => 'plaintext-should-not-work'],
            ],
        ])->assertUnprocessable();
    }

    public function test_updating_a_connection_replaces_a_plaintext_tier_value(): void
    {
        $user = User::factory()->create();
        $definition = ConnectionAttributeDefinition::create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'label_ciphertext' => 'opaque-label',
            'type' => 'text',
            'is_e2ee' => false,
        ]);
        $connection = Connection::create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'name_ciphertext' => 'opaque-name',
        ]);
        ConnectionAttributeValue::create([
            'connection_id' => $connection->id,
            'attribute_definition_id' => $definition->id,
            'value_appkey_ciphertext' => Crypt::encryptString('old.user'),
        ]);

        $response = $this->actingAs($user)->patchJson("/dashboard/connections/{$connection->id}", [
            'attribute_values' => [
                ['attribute_definition_id' => $definition->id, 'value' => 'new.user'],
            ],
        ]);

        $response->assertOk();
        $stored = ConnectionAttributeValue::where('attribute_definition_id', $definition->id)->firstOrFail();
        $this->assertSame('new.user', Crypt::decryptString($stored->value_appkey_ciphertext));
    }
}
