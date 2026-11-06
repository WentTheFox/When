<?php

namespace Tests\Feature;

use App\Models\ConnectionAttributeDefinition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** The "link_list" custom field type: one fully E2EE value (like "list"); the links are validated in the browser. */
class LinkListAttributeTypeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_link_list_field_can_be_created(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/dashboard/connection-attribute-definitions', [
            'id' => (string) Str::uuid(), 'label_ciphertext' => 'opaque', 'type' => 'link_list',
        ])->assertCreated();

        $this->assertSame('link_list', ConnectionAttributeDefinition::first()->type);
    }

    public function test_a_link_list_value_is_one_opaque_ciphertext_and_nothing_else(): void
    {
        $user = User::factory()->create();
        $definition = ConnectionAttributeDefinition::create([
            'id' => (string) Str::uuid(), 'user_id' => $user->id, 'label_ciphertext' => 'opaque', 'type' => 'link_list',
        ]);

        $this->actingAs($user)->postJson('/dashboard/connections', [
            'id' => (string) Str::uuid(),
            'name_ciphertext' => 'opaque',
            'attribute_values' => [['attribute_definition_id' => $definition->id, 'value_ciphertext' => 'opaque-links-blob']],
        ])->assertCreated();

        $row = DB::table('connection_attribute_values')->first();
        $this->assertSame('opaque-links-blob', $row->value_ciphertext);
        $this->assertNull($row->identifier_value);
        $this->assertNull($row->identifier_list_value);
        $this->assertNull($row->boolean_value);
    }

    public function test_a_link_list_value_cannot_be_sent_as_a_plaintext_array(): void
    {
        $user = User::factory()->create();
        $definition = ConnectionAttributeDefinition::create([
            'id' => (string) Str::uuid(), 'user_id' => $user->id, 'label_ciphertext' => 'opaque', 'type' => 'link_list',
        ]);

        $this->actingAs($user)->postJson('/dashboard/connections', [
            'id' => (string) Str::uuid(),
            'name_ciphertext' => 'opaque',
            'attribute_values' => [['attribute_definition_id' => $definition->id, 'identifier_list_value' => ['https://a.example']]],
        ])->assertUnprocessable();

        $this->assertSame(0, DB::table('connection_attribute_values')->count());
    }
}
