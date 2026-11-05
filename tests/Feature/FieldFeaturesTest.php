<?php

namespace Tests\Feature;

use App\Models\ConnectionAttributeDefinition;
use App\Models\User;
use App\Support\FieldFeatures;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A custom field a site feature depends on can't be deleted. The registry
 * (App\Support\FieldFeatures) is what every deletion path asks, and these
 * tests are written against the registry rather than a fixed list of
 * features, so a newly registered feature is covered without touching them.
 */
class FieldFeaturesTest extends TestCase
{
    use RefreshDatabase;

    /** Foreign keys to custom fields that are deliberately NOT site-feature bindings. */
    private const NOT_FEATURES = [
        // Per-connection values simply go with their field.
        'connection_attribute_values.attribute_definition_id',
    ];

    private function definition(User $user): ConnectionAttributeDefinition
    {
        return ConnectionAttributeDefinition::create([
            'id' => (string) Str::uuid(), 'user_id' => $user->id, 'label_ciphertext' => 'opaque', 'type' => 'text',
        ]);
    }

    private function bind(array $binding, User $user, ?string $definitionId): void
    {
        DB::table($binding['table'])->where($binding['user_column'], $user->getKey())->update([$binding['column'] => $definitionId]);
    }

    /** @return list<string> "table.column" of every foreign key that points at connection_attribute_definitions */
    private function foreignKeysToFields(): array
    {
        return collect(DB::select(<<<'SQL'
            SELECT c.conrelid::regclass::text AS tbl, a.attname AS col
            FROM pg_constraint c
            JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = ANY (c.conkey)
            WHERE c.contype = 'f' AND c.confrelid = 'connection_attribute_definitions'::regclass
        SQL))->map(fn ($row) => "{$row->tbl}.{$row->col}")->sort()->values()->all();
    }

    /** @return list<string> Foreign keys to custom fields that are neither registered features nor explicitly allowed. */
    private function unaccountedForeignKeys(): array
    {
        $registered = collect(FieldFeatures::BINDINGS)->map(fn ($b) => "{$b['table']}.{$b['column']}")->all();

        return array_values(array_diff($this->foreignKeysToFields(), $registered, self::NOT_FEATURES));
    }

    public function test_every_foreign_key_to_a_custom_field_is_registered_as_a_feature_or_explicitly_allowed(): void
    {
        $unaccounted = $this->unaccountedForeignKeys();

        $this->assertSame(
            [],
            $unaccounted,
            'These columns point at a custom field but are not registered in App\Support\FieldFeatures::BINDINGS, '
            .'so deleting the field would not be blocked (or would silently null the feature out): '.implode(', ', $unaccounted)
            .'. Register them there — or, if they should not block deletion, add them to NOT_FEATURES in this test.',
        );
    }

    /** Guards the guard: a feature column added without registering it must be caught. */
    public function test_the_guard_flags_a_new_unregistered_foreign_key(): void
    {
        $this->assertSame([], $this->unaccountedForeignKeys());

        // Rolled back with the test's transaction.
        Schema::table('users', function (Blueprint $table) {
            $table->foreignUuid('some_new_feature_definition_id')->nullable()->constrained('connection_attribute_definitions')->nullOnDelete();
        });

        $this->assertSame(['users.some_new_feature_definition_id'], $this->unaccountedForeignKeys());
    }

    public function test_every_registered_binding_is_a_real_foreign_key_to_custom_fields(): void
    {
        $actual = $this->foreignKeysToFields();

        foreach (FieldFeatures::BINDINGS as $name => $binding) {
            $this->assertContains("{$binding['table']}.{$binding['column']}", $actual, "Binding '{$name}' does not match a foreign key to connection_attribute_definitions.");
            $this->assertNotSame('', $binding['label'], "Binding '{$name}' needs a label.");
        }
    }

    public function test_each_feature_blocks_deleting_its_field_and_releases_it_when_unbound(): void
    {
        foreach (FieldFeatures::BINDINGS as $name => $binding) {
            $user = User::factory()->create();
            $definition = $this->definition($user);
            $this->bind($binding, $user, $definition->id);

            $this->assertSame([$binding['label']], FieldFeatures::labelsFor($user, $definition->id), $name);

            $this->actingAs($user)
                ->deleteJson("/dashboard/connection-attribute-definitions/{$definition->id}")
                ->assertUnprocessable()
                ->assertJsonValidationErrors('definition');
            $this->assertNotNull(ConnectionAttributeDefinition::find($definition->id), "{$name}: the field must survive");

            $this->bind($binding, $user, null);
            $this->assertSame([], FieldFeatures::labelsFor($user, $definition->id), $name);

            $this->actingAs($user)->deleteJson("/dashboard/connection-attribute-definitions/{$definition->id}")->assertOk();
            $this->assertNull(ConnectionAttributeDefinition::find($definition->id), "{$name}: deletable once unbound");
        }
    }

    public function test_the_refusal_names_the_features_in_use(): void
    {
        $user = User::factory()->create();
        $definition = $this->definition($user);
        foreach (FieldFeatures::BINDINGS as $binding) {
            $this->bind($binding, $user, $definition->id);
        }

        $message = $this->actingAs($user)
            ->deleteJson("/dashboard/connection-attribute-definitions/{$definition->id}")
            ->assertUnprocessable()
            ->json('errors.definition.0');

        foreach (FieldFeatures::BINDINGS as $binding) {
            $this->assertStringContainsString($binding['label'], $message);
        }
    }

    public function test_one_field_can_serve_several_features_and_unrelated_fields_stay_deletable(): void
    {
        $user = User::factory()->create();
        $shared = $this->definition($user);
        $unrelated = $this->definition($user);
        foreach (FieldFeatures::BINDINGS as $binding) {
            $this->bind($binding, $user, $shared->id);
        }

        $this->assertCount(count(FieldFeatures::BINDINGS), FieldFeatures::labelsFor($user, $shared->id));
        $this->assertSame([], FieldFeatures::labelsFor($user, $unrelated->id));

        $this->actingAs($user)->deleteJson("/dashboard/connection-attribute-definitions/{$unrelated->id}")->assertOk();
    }

    public function test_another_users_binding_does_not_protect_this_users_field(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $definition = $this->definition($owner);

        // Not reachable through the app (settings validation scopes ids to the owner), but the registry must still be per-user.
        foreach (FieldFeatures::BINDINGS as $binding) {
            $this->bind($binding, $other, $definition->id);
        }

        $this->assertSame([], FieldFeatures::labelsFor($owner, $definition->id));
    }
}
