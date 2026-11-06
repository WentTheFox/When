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

/** identifier → identifier_list is a one-way, in-place migration. */
class ConvertIdentifierToListTest extends TestCase
{
    use RefreshDatabase;

    private function definition(User $user, string $type): ConnectionAttributeDefinition
    {
        return ConnectionAttributeDefinition::create([
            'id' => (string) Str::uuid(), 'user_id' => $user->id, 'label_ciphertext' => 'opaque', 'type' => $type,
        ]);
    }

    private function value(User $user, ConnectionAttributeDefinition $definition, ?string $identifier): ConnectionAttributeValue
    {
        $connection = Connection::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'name_ciphertext' => 'opaque']);

        return ConnectionAttributeValue::create([
            'connection_id' => $connection->id,
            'attribute_definition_id' => $definition->id,
            'identifier_value' => $identifier,
        ]);
    }

    private function url(ConnectionAttributeDefinition $definition): string
    {
        return "/dashboard/connection-attribute-definitions/{$definition->id}/convert-to-list";
    }

    public function test_values_become_one_entry_lists_and_the_type_changes_in_place(): void
    {
        $user = User::factory()->create();
        $definition = $this->definition($user, 'identifier');
        $kept = $this->value($user, $definition, '  usr_a  ');
        $empty = $this->value($user, $definition, '   ');

        $this->actingAs($user)->postJson($this->url($definition))->assertOk()->assertJson(['converted' => 1]);

        $definition->refresh();
        $this->assertSame('identifier_list', $definition->type);

        $kept->refresh();
        $this->assertSame(['usr_a'], $kept->identifier_list_value);
        $this->assertNull($kept->identifier_value);
        $this->assertNull(DB::table('connection_attribute_values')->where('id', $kept->id)->value('identifier_value'));

        $this->assertNull(ConnectionAttributeValue::find($empty->id), 'an empty value has nothing to convert');
    }

    public function test_the_stored_list_is_encrypted_at_rest(): void
    {
        $user = User::factory()->create();
        $definition = $this->definition($user, 'identifier');
        $row = $this->value($user, $definition, 'usr-sentinel-123');

        $this->actingAs($user)->postJson($this->url($definition))->assertOk();

        $raw = DB::table('connection_attribute_values')->where('id', $row->id)->value('identifier_list_value');
        $this->assertStringNotContainsString('usr-sentinel-123', (string) $raw);
    }

    public function test_it_cannot_be_converted_back_or_converted_twice(): void
    {
        $user = User::factory()->create();
        $definition = $this->definition($user, 'identifier');

        $this->actingAs($user)->postJson($this->url($definition))->assertOk();
        $this->actingAs($user)->postJson($this->url($definition))->assertUnprocessable()->assertJsonValidationErrors('definition');

        $this->actingAs($user)->patchJson("/dashboard/connection-attribute-definitions/{$definition->id}", [
            'label_ciphertext' => 'x', 'type' => 'identifier',
        ])->assertOk();
        $this->assertSame('identifier_list', $definition->refresh()->type, 'update() never changes the type');
    }

    public function test_only_identifier_fields_convert(): void
    {
        $user = User::factory()->create();

        foreach (['text', 'identifier_list', 'boolean'] as $type) {
            $this->actingAs($user)->postJson($this->url($this->definition($user, $type)))->assertUnprocessable();
        }
    }

    public function test_another_owner_cannot_convert_my_field(): void
    {
        $owner = User::factory()->create();
        $definition = $this->definition($owner, 'identifier');

        $this->actingAs(User::factory()->create())->postJson($this->url($definition))->assertNotFound();
        $this->assertSame('identifier', $definition->refresh()->type);
    }

    public function test_a_selected_vrchat_field_stays_selected_and_valid(): void
    {
        $user = User::factory()->create();
        $definition = $this->definition($user, 'identifier');
        $user->update(['vrchat_user_id_definition_id' => $definition->id]);

        $this->actingAs($user)->postJson($this->url($definition))->assertOk();

        $this->assertSame($definition->id, $user->refresh()->vrchat_user_id_definition_id);
    }
}
