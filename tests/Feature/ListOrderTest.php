<?php

namespace Tests\Feature;

use App\Http\Controllers\Dashboard\ListOrderController;
use App\Models\Connection;
use App\Models\ConnectionAttributeDefinition;
use App\Models\ConnectionSource;
use App\Models\ConnectionSourceCategory;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The Connections page's four lists keep an integer position chosen by the
 * client (names are E2EE, so the server can't sort them): the page is served in
 * that order, and the client saves a new one when it creates or renames things.
 */
class ListOrderTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{class-string, array<string, mixed>, string}> kind => [model, extra attributes, inertia prop] */
    private static function kinds(): array
    {
        return [
            'connections' => [Connection::class, ['name_ciphertext' => 'x'], 'connections'],
            'sources' => [ConnectionSource::class, ['name_ciphertext' => 'x'], 'sources'],
            'categories' => [ConnectionSourceCategory::class, ['name_ciphertext' => 'x'], 'categories'],
            'attributes' => [ConnectionAttributeDefinition::class, ['label_ciphertext' => 'x', 'type' => 'text'], 'attributeDefinitions'],
        ];
    }

    private function record(string $model, array $extra, User $user, int $order = 0): object
    {
        return $model::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'sort_order' => $order] + $extra);
    }

    public function test_the_four_lists_are_covered(): void
    {
        $this->assertSame(array_keys(ListOrderController::TABLES), array_keys(self::kinds()));
    }

    public function test_each_list_is_served_in_its_saved_order_not_creation_order(): void
    {
        foreach (self::kinds() as $kind => [$model, $extra, $prop]) {
            $user = User::factory()->create();
            $first = $this->record($model, $extra, $user, 2);
            $second = $this->record($model, $extra, $user, 0);
            $third = $this->record($model, $extra, $user, 1);

            $this->actingAs($user)->get('/dashboard/connections')->assertInertia(
                fn (AssertableInertia $page) => $page->where($prop.'.0.id', $second->id)->where($prop.'.1.id', $third->id)->where($prop.'.2.id', $first->id),
            );
        }
    }

    public function test_ties_fall_back_to_creation_order(): void
    {
        $user = User::factory()->create();
        $older = $this->record(ConnectionSource::class, ['name_ciphertext' => 'x'], $user);
        $older->forceFill(['created_at' => Carbon::now()->subDay()])->save();
        $newer = $this->record(ConnectionSource::class, ['name_ciphertext' => 'x'], $user);

        $this->actingAs($user)->get('/dashboard/connections')->assertInertia(
            fn (AssertableInertia $page) => $page->where('sources.0.id', $older->id)->where('sources.1.id', $newer->id),
        );
    }

    public function test_the_client_can_save_a_new_order_for_each_list(): void
    {
        foreach (self::kinds() as $kind => [$model, $extra]) {
            $user = User::factory()->create();
            $a = $this->record($model, $extra, $user, 0);
            $b = $this->record($model, $extra, $user, 1);
            $c = $this->record($model, $extra, $user, 2);

            $this->actingAs($user)->postJson("/dashboard/order/{$kind}", ['ids' => [$c->id, $a->id, $b->id]])
                ->assertOk()->assertJson(['updated' => 3]);

            $this->assertSame([1, 2, 0], [$a->fresh()->sort_order, $b->fresh()->sort_order, $c->fresh()->sort_order], $kind);
        }
    }

    public function test_another_users_records_are_never_reordered(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $mine = $this->record(Connection::class, ['name_ciphertext' => 'x'], $owner, 5);
        $theirs = $this->record(Connection::class, ['name_ciphertext' => 'x'], $stranger, 7);

        $this->actingAs($owner)->postJson('/dashboard/order/connections', ['ids' => [$theirs->id, $mine->id]])
            ->assertOk()->assertJson(['updated' => 1]);

        $this->assertSame(7, $theirs->fresh()->sort_order);
        $this->assertSame(1, $mine->fresh()->sort_order);
    }

    public function test_ids_must_be_distinct_uuids_and_the_kind_must_exist(): void
    {
        $user = User::factory()->create();
        $id = (string) Str::uuid();

        $this->actingAs($user)->postJson('/dashboard/order/connections', ['ids' => ['nope']])->assertUnprocessable();
        $this->actingAs($user)->postJson('/dashboard/order/connections', ['ids' => [$id, $id]])->assertUnprocessable();
        $this->actingAs($user)->postJson('/dashboard/order/connections', [])->assertUnprocessable();
        $this->actingAs($user)->postJson('/dashboard/order/bogus', ['ids' => [$id]])->assertNotFound();
    }

    public function test_requires_login(): void
    {
        $this->postJson('/dashboard/order/connections', ['ids' => []])->assertUnauthorized();
    }

    public function test_a_new_record_goes_to_the_end_until_the_client_places_it(): void
    {
        $user = User::factory()->create();
        $this->record(ConnectionSource::class, ['name_ciphertext' => 'x'], $user, 4);

        $id = (string) Str::uuid();
        $this->actingAs($user)->postJson('/dashboard/connection-sources', ['id' => $id, 'name_ciphertext' => 'y'])->assertCreated();

        $this->assertSame(5, ConnectionSource::findOrFail($id)->sort_order);
    }

    public function test_the_first_record_in_an_empty_list_starts_at_zero(): void
    {
        $user = User::factory()->create();
        $id = (string) Str::uuid();

        $this->actingAs($user)->postJson('/dashboard/connections', ['id' => $id, 'name_ciphertext' => 'y'])->assertCreated();

        $this->assertSame(0, Connection::findOrFail($id)->sort_order);
    }

    /** Existing rows start in creation order, per owner. */
    public function test_the_migration_backfills_creation_order_per_owner(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $old = $this->record(ConnectionSource::class, ['name_ciphertext' => 'x'], $a);
        $old->forceFill(['created_at' => '2026-01-01 00:00:00'])->save();
        $mid = $this->record(ConnectionSource::class, ['name_ciphertext' => 'x'], $a);
        $mid->forceFill(['created_at' => '2026-01-02 00:00:00'])->save();
        $other = $this->record(ConnectionSource::class, ['name_ciphertext' => 'x'], $b);

        foreach (['connections', 'connection_sources', 'connection_source_categories', 'connection_attribute_definitions'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropIndex(['user_id', 'sort_order']);
                $blueprint->dropColumn('sort_order');
            });
        }

        (require database_path('migrations/2026_11_05_050000_add_sort_order_to_master_lists.php'))->up();

        $this->assertSame(0, (int) DB::table('connection_sources')->where('id', $old->id)->value('sort_order'));
        $this->assertSame(1, (int) DB::table('connection_sources')->where('id', $mid->id)->value('sort_order'));
        $this->assertSame(0, (int) DB::table('connection_sources')->where('id', $other->id)->value('sort_order'), 'positions are per owner');
    }
}
