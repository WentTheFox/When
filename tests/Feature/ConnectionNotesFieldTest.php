<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\ConnectionAttributeDefinition;
use App\Models\ConnectionAttributeValue;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Notes are an ordinary multi-line custom field now. The server only ever moves the ciphertext: the old note and a
 * field value share the connection's own key, so it never reads either.
 */
class ConnectionNotesFieldTest extends TestCase
{
    use RefreshDatabase;

    private function connection(User $user, ?string $notes = null): Connection
    {
        return Connection::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'name_ciphertext' => 'opaque-name', 'notes_ciphertext' => $notes]);
    }

    /** @return array<string, string> */
    private function newField(): array
    {
        return ['id' => (string) Str::uuid(), 'label_ciphertext' => 'label-blob', 'icon_rules_ciphertext' => 'rules-blob'];
    }

    public function test_the_first_call_creates_the_field_and_moves_every_note_as_opaque_ciphertext(): void
    {
        $user = User::factory()->create();
        $withNotes = $this->connection($user, 'notes-cipher-1');
        $other = $this->connection($user, 'notes-cipher-2');
        $without = $this->connection($user);
        $payload = $this->newField();

        $response = $this->actingAs($user)->postJson('/dashboard/connections/notes-field', $payload)->assertOk()
            ->assertJsonPath('created', true)
            ->assertJsonPath('definition.id', $payload['id'])
            ->assertJsonPath('definition.type', 'textarea')
            ->assertJsonPath('conflicts', []);

        $definition = ConnectionAttributeDefinition::findOrFail($payload['id']);
        $this->assertSame('textarea', $definition->type);
        $this->assertSame('label-blob', $definition->label_ciphertext);
        $this->assertSame('rules-blob', $definition->icon_rules_ciphertext);
        $this->assertSame($payload['id'], $user->refresh()->notes_field_definition_id);

        // The ciphertext is carried over untouched, and the old column is emptied.
        $this->assertSame('notes-cipher-1', ConnectionAttributeValue::where('connection_id', $withNotes->id)->where('attribute_definition_id', $definition->id)->value('value_ciphertext'));
        $this->assertSame('notes-cipher-2', ConnectionAttributeValue::where('connection_id', $other->id)->where('attribute_definition_id', $definition->id)->value('value_ciphertext'));
        $this->assertNull($withNotes->refresh()->notes_ciphertext);
        $this->assertNull($other->refresh()->notes_ciphertext);
        $this->assertSame(0, ConnectionAttributeValue::where('connection_id', $without->id)->count());

        // The moved connections come back whole, so the page can splice them in.
        $this->assertEqualsCanonicalizing([$withNotes->id, $other->id], collect($response->json('connections'))->pluck('id')->all());
        $this->assertNull(collect($response->json('connections'))->firstWhere('id', $withNotes->id)['notes_ciphertext']);
    }

    public function test_calling_again_reuses_the_field_and_creates_nothing_new(): void
    {
        $user = User::factory()->create();
        $this->connection($user, 'notes-cipher');
        $first = $this->newField();
        $this->actingAs($user)->postJson('/dashboard/connections/notes-field', $first)->assertOk();

        $late = $this->connection($user, 'late-notes'); // e.g. written by a tab that had not reloaded yet
        $second = $this->actingAs($user)->postJson('/dashboard/connections/notes-field', $this->newField())->assertOk()
            ->assertJsonPath('created', false)
            ->assertJsonPath('definition.id', $first['id']);

        $this->assertSame(1, ConnectionAttributeDefinition::where('user_id', $user->id)->count());
        $this->assertSame('late-notes', ConnectionAttributeValue::where('connection_id', $late->id)->value('value_ciphertext'));
        $this->assertSame([$late->id], collect($second->json('connections'))->pluck('id')->all());
    }

    public function test_a_connection_that_already_has_a_value_is_a_conflict_the_browser_merges(): void
    {
        $user = User::factory()->create();
        $field = $this->newField();
        $this->actingAs($user)->postJson('/dashboard/connections/notes-field', $field)->assertOk();

        $clash = $this->connection($user, 'stale-tab-notes');
        ConnectionAttributeValue::create(['connection_id' => $clash->id, 'attribute_definition_id' => $field['id'], 'value_ciphertext' => 'typed-in-the-field']);

        $this->actingAs($user)->postJson('/dashboard/connections/notes-field', [])->assertOk()
            ->assertJsonPath('conflicts', [$clash->id]);

        // Nothing was lost or overwritten while it waited for the browser.
        $this->assertSame('stale-tab-notes', $clash->refresh()->notes_ciphertext);
        $this->assertSame('typed-in-the-field', ConnectionAttributeValue::where('connection_id', $clash->id)->value('value_ciphertext'));

        $this->actingAs($user)->postJson('/dashboard/connections/notes-field', ['values' => [['connection_id' => $clash->id, 'value_ciphertext' => 'merged-by-browser']]])->assertOk()
            ->assertJsonPath('conflicts', []);

        $this->assertSame('merged-by-browser', ConnectionAttributeValue::where('connection_id', $clash->id)->where('attribute_definition_id', $field['id'])->value('value_ciphertext'));
        $this->assertNull($clash->refresh()->notes_ciphertext);
        $this->assertSame(1, ConnectionAttributeValue::where('connection_id', $clash->id)->count());
    }

    public function test_values_can_be_saved_without_any_old_notes_such_as_a_vrchat_note_carried_over(): void
    {
        $user = User::factory()->create();
        $connection = $this->connection($user);
        $this->actingAs($user)->postJson('/dashboard/connections/notes-field', $this->newField())->assertOk();

        $this->actingAs($user)->postJson('/dashboard/connections/notes-field', ['values' => [['connection_id' => $connection->id, 'value_ciphertext' => 'imported']]])
            ->assertOk()
            ->assertJsonPath('connections.0.id', $connection->id);

        $this->assertSame('imported', ConnectionAttributeValue::where('connection_id', $connection->id)->value('value_ciphertext'));
    }

    public function test_the_first_call_has_to_say_how_to_create_the_field(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/dashboard/connections/notes-field', [])->assertUnprocessable()->assertJsonValidationErrors('id');
        $this->actingAs($user)->postJson('/dashboard/connections/notes-field', ['id' => (string) Str::uuid()])->assertUnprocessable();

        $this->assertSame(0, ConnectionAttributeDefinition::where('user_id', $user->id)->count());
    }

    public function test_a_deleted_notes_field_is_made_again_with_the_new_id(): void
    {
        $user = User::factory()->create();
        $first = $this->newField();
        $this->actingAs($user)->postJson('/dashboard/connections/notes-field', $first)->assertOk();
        ConnectionAttributeDefinition::findOrFail($first['id'])->forceDelete();

        $second = $this->newField();
        $this->actingAs($user)->postJson('/dashboard/connections/notes-field', $second)->assertOk()->assertJsonPath('created', true);

        $this->assertSame($second['id'], $user->refresh()->notes_field_definition_id);
    }

    public function test_it_never_touches_or_accepts_another_owners_connections(): void
    {
        $user = User::factory()->create();
        $stranger = User::factory()->create();
        $theirs = $this->connection($stranger, 'their-notes');

        $this->actingAs($user)->postJson('/dashboard/connections/notes-field', $this->newField())->assertOk();
        $this->assertSame('their-notes', $theirs->refresh()->notes_ciphertext);
        $this->assertSame(0, ConnectionAttributeValue::where('connection_id', $theirs->id)->count());

        $this->actingAs($user)->postJson('/dashboard/connections/notes-field', ['values' => [['connection_id' => $theirs->id, 'value_ciphertext' => 'sneaky']]])
            ->assertUnprocessable();
        $this->assertSame(0, ConnectionAttributeValue::where('connection_id', $theirs->id)->count());
    }

    public function test_the_connections_page_says_which_field_holds_the_notes(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/dashboard/connections')->assertInertia(fn ($page) => $page->where('notesFieldId', null));

        $field = $this->newField();
        $this->actingAs($user)->postJson('/dashboard/connections/notes-field', $field)->assertOk();
        $this->actingAs($user->fresh())->get('/dashboard/connections')->assertInertia(fn ($page) => $page->where('notesFieldId', $field['id']));
    }
}
