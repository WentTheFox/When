<?php

namespace Tests\Feature;

use App\Models\ConnectionAttributeDefinition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** The setting that picks which custom field lists a connection's other names. */
class AlternativeNamesSettingTest extends TestCase
{
    use RefreshDatabase;

    private function definition(User $user, string $type): ConnectionAttributeDefinition
    {
        return ConnectionAttributeDefinition::create([
            'id' => (string) Str::uuid(), 'user_id' => $user->id, 'label_ciphertext' => 'opaque', 'type' => $type,
        ]);
    }

    public function test_accepts_an_identifier_list_or_a_multi_line_field_and_can_be_cleared(): void
    {
        $user = User::factory()->create();

        foreach (['identifier_list', 'textarea'] as $type) {
            $field = $this->definition($user, $type);
            $this->actingAs($user)->patch('/settings', ['alternative_names_definition_id' => $field->id])->assertSessionHasNoErrors();
            $this->assertSame($field->id, $user->fresh()->alternative_names_definition_id);
        }

        $this->actingAs($user)->patch('/settings', ['alternative_names_definition_id' => null])->assertSessionHasNoErrors();
        $this->assertNull($user->fresh()->alternative_names_definition_id);
    }

    public function test_rejects_other_types_and_other_owners_fields(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        foreach ([$this->definition($user, 'text'), $this->definition($user, 'identifier'), $this->definition($other, 'textarea')] as $field) {
            $this->actingAs($user)->patch('/settings', ['alternative_names_definition_id' => $field->id])
                ->assertSessionHasErrors('alternative_names_definition_id');
        }
    }

    public function test_saving_another_card_leaves_the_field_alone(): void
    {
        $user = User::factory()->create();
        $field = $this->definition($user, 'textarea');
        $user->forceFill(['alternative_names_definition_id' => $field->id])->save();

        $this->actingAs($user)->patch('/settings', ['week_start' => 1])->assertSessionHasNoErrors();

        $this->assertSame($field->id, $user->fresh()->alternative_names_definition_id);
    }

    public function test_the_connections_page_receives_the_field_and_the_settings_page_the_candidates(): void
    {
        $user = User::factory()->create();
        $list = $this->definition($user, 'identifier_list');
        $this->definition($user, 'text');
        $user->forceFill(['alternative_names_definition_id' => $list->id])->save();

        $this->actingAs($user)->get('/dashboard/connections')
            ->assertInertia(fn ($page) => $page->where('alternativeNamesFieldId', $list->id));
        $this->actingAs($user)->get('/settings')
            ->assertInertia(fn ($page) => $page->has('alternativeNamesDefinitions', 1)->where('settings.alternative_names_definition_id', $list->id));
    }

    public function test_the_field_is_protected_from_deletion_while_selected(): void
    {
        $user = User::factory()->create();
        $field = $this->definition($user, 'textarea');
        $user->forceFill(['alternative_names_definition_id' => $field->id])->save();

        $this->actingAs($user)->deleteJson("/dashboard/connection-attribute-definitions/{$field->id}")->assertStatus(422);
    }
}
