<?php

namespace Tests\Feature;

use App\Models\ConnectionAttributeDefinition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** The "url" custom field type: one fully E2EE value; validating it is the browser's job, the server only sees a blob. */
class UrlAttributeTypeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_url_field_can_be_created(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/dashboard/connection-attribute-definitions', [
            'id' => (string) Str::uuid(), 'label_ciphertext' => 'opaque', 'type' => 'url',
        ])->assertCreated();

        $this->assertSame('url', ConnectionAttributeDefinition::first()->type);
    }

    public function test_a_url_value_is_one_opaque_ciphertext_and_nothing_else(): void
    {
        $user = User::factory()->create();
        $definition = ConnectionAttributeDefinition::create([
            'id' => (string) Str::uuid(), 'user_id' => $user->id, 'label_ciphertext' => 'opaque', 'type' => 'url',
        ]);

        $this->actingAs($user)->postJson('/dashboard/connections', [
            'id' => (string) Str::uuid(),
            'name_ciphertext' => 'opaque',
            'attribute_values' => [['attribute_definition_id' => $definition->id, 'value_ciphertext' => 'opaque-url-blob']],
        ])->assertCreated();

        $row = DB::table('connection_attribute_values')->first();
        $this->assertSame('opaque-url-blob', $row->value_ciphertext);
        $this->assertNull($row->identifier_value);
        $this->assertNull($row->identifier_list_value);
        $this->assertNull($row->boolean_value);
    }

    public function test_a_url_value_cannot_be_sent_as_plaintext(): void
    {
        $user = User::factory()->create();
        $definition = ConnectionAttributeDefinition::create([
            'id' => (string) Str::uuid(), 'user_id' => $user->id, 'label_ciphertext' => 'opaque', 'type' => 'url',
        ]);

        $this->actingAs($user)->postJson('/dashboard/connections', [
            'id' => (string) Str::uuid(),
            'name_ciphertext' => 'opaque',
            'attribute_values' => [['attribute_definition_id' => $definition->id, 'identifier_value' => 'https://example.com']],
        ])->assertUnprocessable();

        $this->assertSame(0, DB::table('connection_attribute_values')->count());
    }
}
