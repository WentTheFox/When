<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\ConnectionAttributeDefinition;
use App\Models\ConnectionAttributeValue;
use App\Models\ShareLink;
use App\Models\ShareLinkCache;
use App\Models\ShareLinkWord;
use App\Models\User;
use App\Services\Calendar\HighlightWordsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** identifier_list values (§0.2 tier) as the source of share-link highlight tokens, with NO fallback to the legacy words. */
class HighlightIdentifierListTest extends TestCase
{
    use RefreshDatabase;

    private function definition(User $user, string $type = 'identifier_list'): ConnectionAttributeDefinition
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

    private function legacyWords(ShareLink $link, array $words): void
    {
        foreach ($words as $word) {
            ShareLinkWord::create(['share_link_id' => $link->id, 'word_ciphertext' => Crypt::encryptString($word)]);
        }
    }

    private function cache(ShareLink $link): void
    {
        ShareLinkCache::create([
            'share_link_id' => $link->id, 'ciphertext' => 'x',
            'computed_range_start' => now(), 'computed_range_end' => now()->addDay(), 'encrypted_at' => now(),
        ]);
    }

    public function test_list_is_encrypted_at_rest_and_returned_decrypted(): void
    {
        $user = User::factory()->create();
        $definition = $this->definition($user);

        $response = $this->actingAs($user)->postJson('/dashboard/connections', [
            'id' => (string) Str::uuid(),
            'name_ciphertext' => 'opaque',
            'attribute_values' => [['attribute_definition_id' => $definition->id, 'identifier_list_value' => ['alpha', ' beta ', 'alpha', '']]],
        ])->assertCreated();

        $response->assertJsonPath('attribute_values.0.identifier_list_value', ['alpha', 'beta']);
        $raw = DB::table('connection_attribute_values')->first();
        $this->assertStringNotContainsString('alpha', $raw->identifier_list_value);
        $this->assertNull($raw->value_ciphertext);
        $this->assertNull($raw->identifier_value);
    }

    public function test_wrong_value_shape_for_the_type_is_rejected(): void
    {
        $user = User::factory()->create();
        $list = $this->definition($user);
        $text = $this->definition($user, 'text');

        $this->actingAs($user)->postJson('/dashboard/connections', [
            'id' => (string) Str::uuid(), 'name_ciphertext' => 'opaque',
            'attribute_values' => [['attribute_definition_id' => $list->id, 'value_ciphertext' => 'blob']],
        ])->assertUnprocessable();

        $this->actingAs($user)->postJson('/dashboard/connections', [
            'id' => (string) Str::uuid(), 'name_ciphertext' => 'opaque',
            'attribute_values' => [['attribute_definition_id' => $text->id, 'identifier_list_value' => ['plain']]],
        ])->assertUnprocessable();
    }

    public function test_nothing_is_highlighted_until_a_field_is_selected_and_filled(): void
    {
        $user = User::factory()->create();
        $link = ShareLink::factory()->create(['user_id' => $user->id]);
        $this->legacyWords($link, ['legacy']);
        $connection = $this->connection($user, $link);
        $definition = $this->definition($user);
        $resolver = app(HighlightWordsResolver::class);

        // No field chosen: legacy words are ignored.
        $this->assertSame(['words' => [], 'source' => 'none'], $resolver->resolve($link->fresh(), $user->fresh()));

        $user->update(['highlight_identifier_definition_id' => $definition->id]);

        // Field chosen but the connection has no value yet: still nothing.
        $this->assertSame([], $resolver->forShareLink($link->fresh(), $user->fresh()));

        ConnectionAttributeValue::create([
            'connection_id' => $connection->id, 'attribute_definition_id' => $definition->id, 'identifier_list_value' => ['from-connection'],
        ]);
        $this->assertSame(['words' => ['from-connection'], 'source' => 'connection'], $resolver->resolve($link->fresh(), $user->fresh()));
    }

    public function test_link_without_a_connection_highlights_nothing_even_with_a_field_selected(): void
    {
        $user = User::factory()->create();
        $user->update(['highlight_identifier_definition_id' => $this->definition($user)->id]);
        $link = ShareLink::factory()->create(['user_id' => $user->id]);
        $this->legacyWords($link, ['legacy']);

        $this->assertSame([], app(HighlightWordsResolver::class)->forShareLink($link, $user->fresh()));
    }

    public function test_setting_only_accepts_own_identifier_list_definitions(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($user)->patch('/settings', ['highlight_identifier_definition_id' => $this->definition($other)->id])
            ->assertSessionHasErrors('highlight_identifier_definition_id');
        $this->actingAs($user)->patch('/settings', ['highlight_identifier_definition_id' => $this->definition($user, 'identifier')->id])
            ->assertSessionHasErrors('highlight_identifier_definition_id');

        $own = $this->definition($user);
        $link = ShareLink::factory()->create(['user_id' => $user->id]);
        $this->cache($link);

        $this->actingAs($user)->patch('/settings', ['highlight_identifier_definition_id' => $own->id])->assertSessionHasNoErrors();
        $this->assertSame($own->id, $user->fresh()->highlight_identifier_definition_id);
        $this->assertNull(ShareLinkCache::where('share_link_id', $link->id)->first(), 'switching source drops cached results');

        $this->actingAs($user)->patch('/settings', ['highlight_identifier_definition_id' => null])->assertSessionHasNoErrors();
        $this->assertNull($user->fresh()->highlight_identifier_definition_id);
    }

    public function test_deleting_the_selected_definition_clears_the_setting(): void
    {
        $user = User::factory()->create();
        $definition = $this->definition($user);
        $user->update(['highlight_identifier_definition_id' => $definition->id]);

        $this->actingAs($user)->deleteJson("/dashboard/connection-attribute-definitions/{$definition->id}")->assertOk();

        $this->assertNull($user->fresh()->highlight_identifier_definition_id);
    }

    public function test_editing_the_connection_list_invalidates_the_tied_links_cache(): void
    {
        $user = User::factory()->create();
        $definition = $this->definition($user);
        $user->update(['highlight_identifier_definition_id' => $definition->id]);
        $link = ShareLink::factory()->create(['user_id' => $user->id]);
        $connection = $this->connection($user, $link);
        $this->cache($link);

        // Unrelated edit: cache survives.
        $this->actingAs($user)->patchJson("/dashboard/connections/{$connection->id}", ['archived' => true])->assertOk();
        $this->assertNotNull(ShareLinkCache::where('share_link_id', $link->id)->first());

        $this->actingAs($user)->patchJson("/dashboard/connections/{$connection->id}", [
            'attribute_values' => [['attribute_definition_id' => $definition->id, 'identifier_list_value' => ['new']]],
        ])->assertOk();
        $this->assertNull(ShareLinkCache::where('share_link_id', $link->id)->first());
    }
}
