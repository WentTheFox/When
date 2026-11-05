<?php

namespace Tests\Feature;

use App\Models\ConnectionAttributeDefinition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConnectionAttributeDefinitionTest extends TestCase
{
    use RefreshDatabase;

    private function definition(User $user, string $type = 'text'): ConnectionAttributeDefinition
    {
        return ConnectionAttributeDefinition::create([
            'id' => (string) Str::uuid(), 'user_id' => $user->id, 'label_ciphertext' => 'old-label', 'type' => $type,
        ]);
    }

    public function test_the_label_can_be_renamed(): void
    {
        $owner = User::factory()->create();
        $definition = $this->definition($owner, 'radio');

        $this->actingAs($owner)
            ->patchJson("/dashboard/connection-attribute-definitions/{$definition->id}", ['label_ciphertext' => 'new-label'])
            ->assertOk();

        $this->assertSame('new-label', $definition->fresh()->label_ciphertext);
    }

    public function test_renaming_cannot_change_the_type(): void
    {
        $owner = User::factory()->create();
        $definition = $this->definition($owner, 'text');

        $this->actingAs($owner)
            ->patchJson("/dashboard/connection-attribute-definitions/{$definition->id}", [
                'label_ciphertext' => 'new-label',
                'type' => 'identifier',
            ])->assertOk();

        $this->assertSame('text', $definition->fresh()->type);
    }

    public function test_a_label_is_required(): void
    {
        $owner = User::factory()->create();
        $definition = $this->definition($owner);

        $this->actingAs($owner)
            ->patchJson("/dashboard/connection-attribute-definitions/{$definition->id}", [])
            ->assertUnprocessable();
    }

    public function test_cannot_rename_another_users_definition(): void
    {
        $owner = User::factory()->create();
        $definition = $this->definition(User::factory()->create());

        $this->actingAs($owner)
            ->patchJson("/dashboard/connection-attribute-definitions/{$definition->id}", ['label_ciphertext' => 'hijacked'])
            ->assertNotFound();

        $this->assertSame('old-label', $definition->fresh()->label_ciphertext);
    }

    public function test_the_connections_page_reports_which_fields_drive_sharing(): void
    {
        $owner = User::factory()->create();
        $highlight = $this->definition($owner, 'identifier_list');
        $bypass = $this->definition($owner, 'boolean');
        $owner->update(['highlight_identifier_definition_id' => $highlight->id, 'bypass_dnd_definition_id' => $bypass->id]);

        $this->actingAs($owner)->get('/dashboard/connections')->assertInertia(
            fn ($page) => $page->where('fieldRoles.highlight', $highlight->id)->where('fieldRoles.bypass', $bypass->id),
        );
    }
}
