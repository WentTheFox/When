<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\ConnectionAttributeDefinition;
use App\Models\ConnectionAttributeValue;
use App\Models\User;
use App\Support\FieldMigrations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Changing a custom field's type in place ("Migrate field"): every path in App\Support\FieldMigrations goes through one endpoint. */
class FieldMigrationsTest extends TestCase
{
    use RefreshDatabase;

    private function definition(User $user, string $type, array $extra = []): ConnectionAttributeDefinition
    {
        return ConnectionAttributeDefinition::create([
            'id' => (string) Str::uuid(), 'user_id' => $user->id, 'label_ciphertext' => 'opaque', 'type' => $type,
        ] + $extra);
    }

    private function value(User $user, ConnectionAttributeDefinition $definition, array $columns): ConnectionAttributeValue
    {
        $connection = Connection::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'name_ciphertext' => 'opaque']);

        return ConnectionAttributeValue::create($columns + ['connection_id' => $connection->id, 'attribute_definition_id' => $definition->id]);
    }

    private function migrate(User $user, ConnectionAttributeDefinition $definition, string $to, array $body = [])
    {
        return $this->actingAs($user)->postJson("/dashboard/connection-attribute-definitions/{$definition->id}/migrate", ['to' => $to] + $body);
    }

    public function test_an_e2ee_value_is_swapped_for_the_reencrypted_one_and_the_type_changes_in_place(): void
    {
        $user = User::factory()->create();
        $definition = $this->definition($user, 'textarea');
        $kept = $this->value($user, $definition, ['value_ciphertext' => 'old-1']);
        $emptied = $this->value($user, $definition, ['value_ciphertext' => 'old-2']);

        $this->migrate($user, $definition, 'list', ['values' => [
            ['connection_id' => $kept->connection_id, 'base_ciphertext' => 'old-1', 'value_ciphertext' => 'new-1'],
            ['connection_id' => $emptied->connection_id, 'base_ciphertext' => 'old-2', 'value_ciphertext' => null],
        ]])->assertOk()->assertJson(['converted' => 1]);

        $this->assertSame('list', $definition->fresh()->type);
        $this->assertSame('new-1', $kept->fresh()->value_ciphertext);
        $this->assertNull(ConnectionAttributeValue::find($emptied->id), 'a value that came out empty is removed');
    }

    public function test_nothing_changes_when_a_value_moved_on_or_the_set_of_values_differs(): void
    {
        $user = User::factory()->create();
        $definition = $this->definition($user, 'text');
        $row = $this->value($user, $definition, ['value_ciphertext' => 'edited-elsewhere']);
        $this->value($user, $definition, ['value_ciphertext' => 'unsent']);

        $this->migrate($user, $definition, 'textarea', ['values' => [
            ['connection_id' => $row->connection_id, 'base_ciphertext' => 'what-the-page-read', 'value_ciphertext' => 'new'],
        ]])->assertStatus(409);
        $this->migrate($user, $definition, 'textarea', ['values' => []])->assertStatus(409);

        $this->assertSame('text', $definition->fresh()->type);
        $this->assertSame('edited-elsewhere', $row->fresh()->value_ciphertext);
    }

    public function test_text_leaves_e2ee_for_an_identifier_and_the_old_column_is_cleared(): void
    {
        $user = User::factory()->create();
        $definition = $this->definition($user, 'text');
        $row = $this->value($user, $definition, ['value_ciphertext' => 'old']);

        $this->migrate($user, $definition, 'identifier', ['values' => [
            ['connection_id' => $row->connection_id, 'base_ciphertext' => 'old', 'identifier_value' => '  usr_sentinel  '],
        ]])->assertOk();

        $row->refresh();
        $this->assertSame('identifier', $definition->fresh()->type);
        $this->assertSame('usr_sentinel', $row->identifier_value);
        $this->assertNull($row->value_ciphertext);
        $this->assertStringNotContainsString('usr_sentinel', (string) DB::table('connection_attribute_values')->where('id', $row->id)->value('identifier_value'), 'encrypted at rest');
    }

    public function test_a_list_leaves_e2ee_for_an_identifier_list(): void
    {
        $user = User::factory()->create();
        $definition = $this->definition($user, 'list');
        $row = $this->value($user, $definition, ['value_ciphertext' => 'old']);

        $this->migrate($user, $definition, 'identifier_list', ['values' => [
            ['connection_id' => $row->connection_id, 'base_ciphertext' => 'old', 'identifier_list_value' => ['a', ' b ', '']],
        ]])->assertOk();

        $this->assertSame(['a', 'b'], $row->fresh()->identifier_list_value);
        $this->assertNull($row->fresh()->value_ciphertext);
    }

    public function test_an_identifier_list_enters_e2ee_as_a_list_and_a_stale_base_is_refused(): void
    {
        $user = User::factory()->create();
        $definition = $this->definition($user, 'identifier_list');
        $row = $this->value($user, $definition, ['identifier_list_value' => ['a', 'b']]);

        $this->migrate($user, $definition, 'list', ['values' => [
            ['connection_id' => $row->connection_id, 'base_identifier_list' => ['a'], 'value_ciphertext' => 'x'],
        ]])->assertStatus(409);

        $this->migrate($user, $definition, 'list', ['values' => [
            ['connection_id' => $row->connection_id, 'base_identifier_list' => ['a', 'b'], 'value_ciphertext' => 'cipher'],
        ]])->assertOk();

        $row->refresh();
        $this->assertSame('list', $definition->fresh()->type);
        $this->assertSame('cipher', $row->value_ciphertext);
        $this->assertNull($row->identifier_list_value);
    }

    public function test_yes_no_becomes_a_choice_with_its_options(): void
    {
        $user = User::factory()->create();
        $definition = $this->definition($user, 'boolean');
        $row = $this->value($user, $definition, ['boolean_value' => true]);

        $this->migrate($user, $definition, 'radio', ['values' => [
            ['connection_id' => $row->connection_id, 'base_boolean' => false, 'value_ciphertext' => 'c'],
        ], 'options_ciphertext' => 'opts'])->assertStatus(409);

        $this->migrate($user, $definition, 'radio', ['values' => [
            ['connection_id' => $row->connection_id, 'base_boolean' => true, 'value_ciphertext' => 'c'],
        ]])->assertUnprocessable()->assertJsonValidationErrors('options_ciphertext');

        $this->migrate($user, $definition, 'radio', ['values' => [
            ['connection_id' => $row->connection_id, 'base_boolean' => true, 'value_ciphertext' => 'c'],
        ], 'options_ciphertext' => 'opts'])->assertOk();

        $definition->refresh();
        $this->assertSame('radio', $definition->type);
        $this->assertSame('opts', $definition->options_ciphertext);
        $this->assertNull($row->fresh()->boolean_value);
        $this->assertSame('c', $row->fresh()->value_ciphertext);
    }

    public function test_leaving_a_choice_field_drops_its_options(): void
    {
        $user = User::factory()->create();
        $definition = $this->definition($user, 'radio', ['options_ciphertext' => 'opts']);
        $row = $this->value($user, $definition, ['value_ciphertext' => 'old']);

        $this->migrate($user, $definition, 'text', ['values' => [
            ['connection_id' => $row->connection_id, 'base_ciphertext' => 'old', 'value_ciphertext' => 'same'],
        ]])->assertOk();

        $this->assertNull($definition->fresh()->options_ciphertext);
    }

    public function test_identifier_and_identifier_list_convert_both_ways_on_the_server_alone(): void
    {
        $user = User::factory()->create();
        $definition = $this->definition($user, 'identifier');
        $kept = $this->value($user, $definition, ['identifier_value' => '  usr_a  ']);
        $empty = $this->value($user, $definition, ['identifier_value' => '   ']);

        $this->migrate($user, $definition, 'identifier_list')->assertOk()->assertJson(['converted' => 1]);

        $this->assertSame('identifier_list', $definition->fresh()->type);
        $this->assertSame(['usr_a'], $kept->fresh()->identifier_list_value);
        $this->assertNull($kept->fresh()->identifier_value);
        $this->assertNull(ConnectionAttributeValue::find($empty->id));
        $this->assertStringNotContainsString('usr_a', (string) DB::table('connection_attribute_values')->where('id', $kept->id)->value('identifier_list_value'));

        $kept->update(['identifier_list_value' => ['x', 'y']]);

        $this->migrate($user, $definition, 'identifier')->assertUnprocessable()->assertJsonValidationErrors('strategy');
        $this->migrate($user, $definition, 'identifier', ['strategy' => 'join'])->assertOk();
        $this->assertSame('x, y', $kept->fresh()->identifier_value);
        $this->assertNull($kept->fresh()->identifier_list_value);
    }

    public function test_keeping_the_first_entry(): void
    {
        $user = User::factory()->create();
        $definition = $this->definition($user, 'identifier_list');
        $row = $this->value($user, $definition, ['identifier_list_value' => [' ', 'first', 'second']]);

        $this->migrate($user, $definition, 'identifier', ['strategy' => 'first'])->assertOk();

        $this->assertSame('first', $row->fresh()->identifier_value);
    }

    public function test_only_registered_paths_run(): void
    {
        $user = User::factory()->create();

        $this->migrate($user, $this->definition($user, 'date'), 'number', ['values' => []])->assertUnprocessable();
        $this->migrate($user, $this->definition($user, 'connection'), 'text', ['values' => []])->assertUnprocessable();
        $this->migrate($user, $this->definition($user, 'text'), 'text', ['values' => []])->assertUnprocessable();
    }

    public function test_every_registered_path_starts_and_ends_at_a_known_type(): void
    {
        $types = ['text', 'textarea', 'date', 'number', 'url', 'email', 'phone', 'radio', 'country', 'list', 'identifier', 'identifier_list', 'boolean', 'connection'];

        foreach (FieldMigrations::all() as $path) {
            $this->assertContains($path['from'], $types);
            $this->assertContains($path['to'], $types);
            $this->assertNotSame($path['from'], $path['to']);
        }
    }

    public function test_the_notes_field_never_migrates(): void
    {
        $user = User::factory()->create();
        $notes = $this->definition($user, 'textarea');
        $user->forceFill(['notes_field_definition_id' => $notes->id])->save();

        $this->migrate($user, $notes, 'list', ['values' => []])->assertUnprocessable();
        $this->assertSame('textarea', $notes->fresh()->type);
    }

    public function test_a_binding_the_new_type_cannot_serve_blocks_the_change_unless_released(): void
    {
        $user = User::factory()->create();
        $definition = $this->definition($user, 'identifier_list');
        $user->update(['highlight_identifier_definition_id' => $definition->id, 'alternative_names_definition_id' => $definition->id]);

        $this->migrate($user, $definition, 'list', ['values' => []])->assertUnprocessable()->assertJsonValidationErrors('definition');
        $this->assertSame('identifier_list', $definition->fresh()->type);

        $this->migrate($user, $definition, 'list', ['values' => [], 'release_bindings' => true])->assertOk()->assertJson(['released' => ['Highlight tokens']]);

        $user->refresh();
        $this->assertNull($user->highlight_identifier_definition_id);
        $this->assertSame($definition->id, $user->alternative_names_definition_id, 'a binding the new type still serves is kept');
    }

    public function test_a_vrchat_field_stays_selected_when_an_identifier_becomes_a_list(): void
    {
        $user = User::factory()->create();
        $definition = $this->definition($user, 'identifier');
        $user->update(['vrchat_user_id_definition_id' => $definition->id]);

        $this->migrate($user, $definition, 'identifier_list')->assertOk()->assertJson(['released' => []]);

        $this->assertSame($definition->id, $user->refresh()->vrchat_user_id_definition_id);
    }

    public function test_icon_rules_are_replaced_in_the_same_step(): void
    {
        $user = User::factory()->create();
        $definition = $this->definition($user, 'number', ['icon_rules_ciphertext' => 'old-rules']);

        $this->migrate($user, $definition, 'text', ['values' => [], 'icon_rules_ciphertext' => 'new-rules'])->assertOk();

        $this->assertSame('new-rules', $definition->fresh()->icon_rules_ciphertext);
    }

    public function test_it_cannot_touch_another_owners_field_or_connections(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $theirs = $this->definition($other, 'text');
        $row = $this->value($other, $theirs, ['value_ciphertext' => 'theirs']);

        $this->migrate($user, $theirs, 'textarea', ['values' => []])->assertNotFound();

        $mine = $this->definition($user, 'text');
        $this->migrate($user, $mine, 'textarea', ['values' => [
            ['connection_id' => $row->connection_id, 'base_ciphertext' => 'theirs', 'value_ciphertext' => 'x'],
        ]])->assertUnprocessable();
        $this->assertSame('theirs', $row->fresh()->value_ciphertext);
    }

    public function test_update_never_changes_the_type(): void
    {
        $user = User::factory()->create();
        $definition = $this->definition($user, 'text');

        $this->actingAs($user)->patchJson("/dashboard/connection-attribute-definitions/{$definition->id}", ['label_ciphertext' => 'x', 'type' => 'list'])->assertOk();

        $this->assertSame('text', $definition->fresh()->type);
    }
}
