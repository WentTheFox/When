<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\ConnectionAttributeDefinition;
use App\Models\ConnectionAttributeValue;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** multi-line → E2EE list is a one-way, in-place migration the browser performs (only it can read the values). */
class ConvertMultilineToListTest extends TestCase
{
    use RefreshDatabase;

    private function definition(User $user, string $type = 'textarea'): ConnectionAttributeDefinition
    {
        return ConnectionAttributeDefinition::create([
            'id' => (string) Str::uuid(), 'user_id' => $user->id, 'label_ciphertext' => 'opaque', 'type' => $type,
        ]);
    }

    private function value(User $user, ConnectionAttributeDefinition $definition, string $ciphertext): ConnectionAttributeValue
    {
        $connection = Connection::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'name_ciphertext' => 'opaque']);

        return ConnectionAttributeValue::create([
            'connection_id' => $connection->id, 'attribute_definition_id' => $definition->id, 'value_ciphertext' => $ciphertext,
        ]);
    }

    private function url(ConnectionAttributeDefinition $definition): string
    {
        return "/dashboard/connection-attribute-definitions/{$definition->id}/convert-to-encrypted-list";
    }

    public function test_values_are_swapped_for_the_reencrypted_ones_and_the_type_changes_in_place(): void
    {
        $user = User::factory()->create();
        $definition = $this->definition($user);
        $kept = $this->value($user, $definition, 'old-1');
        $emptied = $this->value($user, $definition, 'old-2');

        $this->actingAs($user)->postJson($this->url($definition), ['values' => [
            ['connection_id' => $kept->connection_id, 'base_ciphertext' => 'old-1', 'value_ciphertext' => 'new-1'],
            ['connection_id' => $emptied->connection_id, 'base_ciphertext' => 'old-2', 'value_ciphertext' => null],
        ]])->assertOk()->assertJson(['converted' => 1]);

        $this->assertSame('list', $definition->fresh()->type);
        $this->assertSame('new-1', $kept->fresh()->value_ciphertext);
        $this->assertNull(ConnectionAttributeValue::find($emptied->id), 'a list that came out empty is removed');
    }

    public function test_nothing_changes_when_a_value_moved_on_since_it_was_read(): void
    {
        $user = User::factory()->create();
        $definition = $this->definition($user);
        $row = $this->value($user, $definition, 'edited-elsewhere');

        $this->actingAs($user)->postJson($this->url($definition), ['values' => [
            ['connection_id' => $row->connection_id, 'base_ciphertext' => 'what-the-page-read', 'value_ciphertext' => 'new'],
        ]])->assertStatus(409);

        $this->assertSame('textarea', $definition->fresh()->type);
        $this->assertSame('edited-elsewhere', $row->fresh()->value_ciphertext);
    }

    public function test_nothing_changes_when_a_value_was_added_or_is_missing(): void
    {
        $user = User::factory()->create();
        $definition = $this->definition($user);
        $this->value($user, $definition, 'unsent');

        $this->actingAs($user)->postJson($this->url($definition), ['values' => []])->assertStatus(409);

        $this->assertSame('textarea', $definition->fresh()->type);
    }

    public function test_only_a_multi_line_field_converts_and_not_the_notes_field(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson($this->url($this->definition($user, 'text')), ['values' => []])->assertUnprocessable();

        $notes = $this->definition($user);
        $user->forceFill(['notes_field_definition_id' => $notes->id])->save();
        $this->actingAs($user)->postJson($this->url($notes), ['values' => []])->assertUnprocessable();
        $this->assertSame('textarea', $notes->fresh()->type);
    }

    public function test_it_cannot_touch_another_owners_field_or_connections(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $theirs = $this->definition($other);
        $row = $this->value($other, $theirs, 'theirs');

        $this->actingAs($user)->postJson($this->url($theirs), ['values' => []])->assertNotFound();

        $mine = $this->definition($user);
        $this->actingAs($user)->postJson($this->url($mine), ['values' => [
            ['connection_id' => $row->connection_id, 'base_ciphertext' => 'theirs', 'value_ciphertext' => 'x'],
        ]])->assertUnprocessable();
        $this->assertSame('theirs', $row->fresh()->value_ciphertext);
    }
}
