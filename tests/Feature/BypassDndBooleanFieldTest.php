<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\ConnectionAttributeDefinition;
use App\Models\ConnectionAttributeValue;
use App\Models\ShareLink;
use App\Models\ShareLinkCache;
use App\Models\User;
use App\Services\Calendar\BypassDndResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** 'boolean' values (§0.2 tier) as the source of a share link's bypass-DND flag, with NO fallback to share_links.bypass_dnd. */
class BypassDndBooleanFieldTest extends TestCase
{
    use RefreshDatabase;

    private function definition(User $user, string $type = 'boolean'): ConnectionAttributeDefinition
    {
        return ConnectionAttributeDefinition::create([
            'id' => (string) Str::uuid(), 'user_id' => $user->id, 'label_ciphertext' => 'opaque', 'type' => $type,
        ]);
    }

    private function connection(User $user, ?ShareLink $link = null): Connection
    {
        // A share link always belongs to a connection; reuse its own.
        return $link?->connection ?? Connection::create([
            'id' => (string) Str::uuid(), 'user_id' => $user->id, 'name_ciphertext' => 'opaque',
        ]);
    }

    public function test_boolean_is_encrypted_at_rest_including_false(): void
    {
        $user = User::factory()->create();
        $definition = $this->definition($user);

        $response = $this->actingAs($user)->postJson('/dashboard/connections', [
            'id' => (string) Str::uuid(), 'name_ciphertext' => 'opaque',
            'attribute_values' => [['attribute_definition_id' => $definition->id, 'boolean_value' => false]],
        ])->assertCreated();

        $response->assertJsonPath('attribute_values.0.boolean_value', false);
        $raw = DB::table('connection_attribute_values')->first();
        $this->assertNotNull($raw->boolean_value);
        $this->assertNotSame('0', $raw->boolean_value);
        $this->assertNull($raw->value_ciphertext);
    }

    public function test_wrong_value_shape_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/dashboard/connections', [
            'id' => (string) Str::uuid(), 'name_ciphertext' => 'opaque',
            'attribute_values' => [['attribute_definition_id' => $this->definition($user)->id, 'value_ciphertext' => 'blob']],
        ])->assertUnprocessable();
    }

    public function test_nobody_bypasses_dnd_until_a_field_is_selected_and_set(): void
    {
        $user = User::factory()->create();
        $link = ShareLink::factory()->create(['user_id' => $user->id, 'bypass_dnd' => true]);
        $connection = $this->connection($user, $link);
        $definition = $this->definition($user);
        $resolver = app(BypassDndResolver::class);

        // The legacy flag is ignored with no field chosen, and with a field but no value.
        $this->assertSame(['value' => false, 'source' => 'none'], $resolver->resolve($link->fresh(), $user->fresh()));
        $user->update(['bypass_dnd_definition_id' => $definition->id]);
        $this->assertFalse($resolver->forShareLink($link->fresh(), $user->fresh()));

        ConnectionAttributeValue::create(['connection_id' => $connection->id, 'attribute_definition_id' => $definition->id, 'boolean_value' => true]);
        $this->assertSame(['value' => true, 'source' => 'connection'], $resolver->resolve($link->fresh(), $user->fresh()));
    }

    public function test_setting_only_accepts_own_boolean_definitions_and_invalidates_caches(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($user)->patch('/settings', ['bypass_dnd_definition_id' => $this->definition($other)->id])
            ->assertSessionHasErrors('bypass_dnd_definition_id');
        $this->actingAs($user)->patch('/settings', ['bypass_dnd_definition_id' => $this->definition($user, 'identifier_list')->id])
            ->assertSessionHasErrors('bypass_dnd_definition_id');

        $own = $this->definition($user);
        $link = ShareLink::factory()->create(['user_id' => $user->id]);
        ShareLinkCache::create(['share_link_id' => $link->id, 'ciphertext' => 'x', 'computed_range_start' => now(), 'computed_range_end' => now()->addDay(), 'encrypted_at' => now()]);

        $this->actingAs($user)->patch('/settings', ['bypass_dnd_definition_id' => $own->id])->assertSessionHasNoErrors();
        $this->assertSame($own->id, $user->fresh()->bypass_dnd_definition_id);
        $this->assertNull(ShareLinkCache::where('share_link_id', $link->id)->first());
    }

    public function test_editing_the_connection_value_invalidates_the_tied_links_cache(): void
    {
        $user = User::factory()->create();
        $definition = $this->definition($user);
        $user->update(['bypass_dnd_definition_id' => $definition->id]);
        $link = ShareLink::factory()->create(['user_id' => $user->id]);
        $connection = $this->connection($user, $link);
        ShareLinkCache::create(['share_link_id' => $link->id, 'ciphertext' => 'x', 'computed_range_start' => now(), 'computed_range_end' => now()->addDay(), 'encrypted_at' => now()]);

        $this->actingAs($user)->patchJson("/dashboard/connections/{$connection->id}", ['archived' => true])->assertOk();
        $this->assertNotNull(ShareLinkCache::where('share_link_id', $link->id)->first());

        $this->actingAs($user)->patchJson("/dashboard/connections/{$connection->id}", [
            'attribute_values' => [['attribute_definition_id' => $definition->id, 'boolean_value' => true]],
        ])->assertOk();
        $this->assertNull(ShareLinkCache::where('share_link_id', $link->id)->first());
    }
}
