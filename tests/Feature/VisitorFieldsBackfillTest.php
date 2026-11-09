<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\ConnectionAttributeDefinition;
use App\Models\ConnectionAttributeValue;
use App\Models\ShareLink;
use App\Models\ShareLinkVisit;
use App\Models\User;
use App\Support\LegacyVisitorData;
use App\Support\VisitorFieldsBackfill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The recorded visitor languages / time zones are copied onto connections as values of 'language' / 'timezone' fields,
 * by the server (VisitorFieldsBackfill, run by a data migration): both types are §0.2 tier (plaintext lists, server-readable
 * on purpose). The new fields have an empty label, which the browser fills in because labels are E2EE.
 */
class VisitorFieldsBackfillTest extends TestCase
{
    use RefreshDatabase;

    private function connection(User $user): Connection
    {
        return Connection::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'name_ciphertext' => 'opaque-name']);
    }

    private function visit(Connection $connection, string $timezone, ?string $locale, bool $mark = true): void
    {
        $link = $connection->shareLink ?? ShareLink::factory()->create(['user_id' => $connection->user_id, 'connection_id' => $connection->id]);
        ShareLinkVisit::factory()->for($link)->create(['timezone' => $timezone, 'locale' => $locale]);
        if ($mark) {
            $link->forceFill(['last_visited_at' => now()])->save();
        }
        $connection->unsetRelation('shareLink');
    }

    public function test_language_is_the_primary_subtag_of_a_locale(): void
    {
        $this->assertSame('en', LegacyVisitorData::languageOf('en-US'));
        $this->assertSame('hu', LegacyVisitorData::languageOf('HU'));
        $this->assertSame('fil', LegacyVisitorData::languageOf('fil_PH'));
        $this->assertNull(LegacyVisitorData::languageOf(''));
        $this->assertNull(LegacyVisitorData::languageOf(null));
        $this->assertNull(LegacyVisitorData::languageOf('x'));
    }

    private function fieldOf(User $user, string $type): ?ConnectionAttributeDefinition
    {
        return ConnectionAttributeDefinition::where('user_id', $user->id)->where('type', $type)->first();
    }

    public function test_recorded_data_lands_on_the_connections_most_frequent_first_in_new_unnamed_fields(): void
    {
        $user = User::factory()->create();
        $a = $this->connection($user);
        $b = $this->connection($user);
        $this->visit($a, 'Europe/Budapest', 'hu-HU');
        $this->visit($a, 'America/New_York', 'en-US');
        $this->visit($a, 'America/New_York', 'en-GB');
        $this->visit($a, 'America/New_York', null);

        VisitorFieldsBackfill::forUser($user);

        $timezones = $this->fieldOf($user, 'timezone');
        $languages = $this->fieldOf($user, 'language');
        // The label is E2EE, so the server can only stage a plaintext name for the browser to encrypt.
        $this->assertNull($timezones->label_ciphertext);
        $this->assertNull($languages->label_ciphertext);
        $this->assertSame('Visitor time zones', $timezones->staged_label);
        $this->assertSame('Visitor languages', $languages->staged_label);
        $this->assertSame(['America/New_York', 'Europe/Budapest'], ConnectionAttributeValue::where('connection_id', $a->id)->where('attribute_definition_id', $timezones->id)->firstOrFail()->identifier_list_value);
        $this->assertSame(['en', 'hu'], ConnectionAttributeValue::where('connection_id', $a->id)->where('attribute_definition_id', $languages->id)->firstOrFail()->identifier_list_value);
        $this->assertSame(0, ConnectionAttributeValue::where('connection_id', $b->id)->count());
    }

    public function test_a_kind_with_no_recorded_data_gets_no_field(): void
    {
        $user = User::factory()->create();
        $this->visit($this->connection($user), 'Europe/Budapest', null);

        VisitorFieldsBackfill::forUser($user);

        $this->assertNotNull($this->fieldOf($user, 'timezone'));
        $this->assertNull($this->fieldOf($user, 'language'));
    }

    public function test_links_never_visited_and_other_owners_are_left_out(): void
    {
        $user = User::factory()->create();
        $stranger = User::factory()->create();
        $this->visit($this->connection($stranger), 'Asia/Tokyo', 'ja');
        $this->visit($this->connection($user), 'Asia/Tokyo', 'ja', mark: false);

        VisitorFieldsBackfill::forAllUsers();

        $this->assertNull($this->fieldOf($user, 'timezone'));
        $this->assertSame(['Asia/Tokyo'], ConnectionAttributeValue::where('attribute_definition_id', $this->fieldOf($stranger, 'timezone')->id)->firstOrFail()->identifier_list_value);
        $this->assertSame(2, ConnectionAttributeDefinition::count());
    }

    public function test_running_again_reuses_the_field_and_keeps_what_the_owner_added(): void
    {
        $user = User::factory()->create();
        $a = $this->connection($user);
        $this->visit($a, 'Asia/Tokyo', 'ja');
        VisitorFieldsBackfill::forUser($user);
        $field = $this->fieldOf($user, 'timezone');
        ConnectionAttributeValue::where('attribute_definition_id', $field->id)->firstOrFail()->forceFill(['identifier_list_value' => ['Europe/Berlin', 'Asia/Tokyo']])->save();

        VisitorFieldsBackfill::forUser($user);

        $this->assertSame(1, ConnectionAttributeDefinition::where('type', 'timezone')->count());
        $this->assertSame(['Europe/Berlin', 'Asia/Tokyo'], ConnectionAttributeValue::where('attribute_definition_id', $field->id)->firstOrFail()->identifier_list_value);
    }

    public function test_the_data_migration_runs_the_backfill_and_its_rollback_removes_only_staged_fields(): void
    {
        $user = User::factory()->create();
        $this->visit($this->connection($user), 'Asia/Tokyo', 'ja');
        $migration = require database_path('migrations/2026_11_09_000200_copy_visitor_languages_and_timezones_to_connections.php');

        $migration->up();
        $this->assertNotNull($this->fieldOf($user, 'timezone'));

        $this->fieldOf($user, 'language')->update(['label_ciphertext' => 'encrypted-by-the-browser', 'staged_label' => null]);
        $migration->down();

        $this->assertNull($this->fieldOf($user, 'timezone'));
        $this->assertNotNull($this->fieldOf($user, 'language'));
    }

    public function test_a_staged_field_is_exported_in_plaintext_without_a_key_ring_entry(): void
    {
        $user = User::factory()->create(['key_ring_ciphertext' => 'ring', 'password' => bcrypt('login-verifier')]);
        $this->visit($this->connection($user), 'Asia/Tokyo', 'ja');
        VisitorFieldsBackfill::forUser($user);

        $response = $this->actingAs($user)->post('/dashboard/account/export', ['password' => 'login-verifier']);
        $response->assertOk();
        $zipPath = tempnam(sys_get_temp_dir(), 'when-export-');
        file_put_contents($zipPath, $response->streamedContent());
        $zip = new \ZipArchive;
        $zip->open($zipPath);
        $records = collect(json_decode((string) $zip->getFromName('connections/attribute-definitions.json'), true)['records']);
        $zip->close();
        unlink($zipPath);

        $timezone = $records->firstWhere('type', 'timezone');
        $this->assertSame('Visitor time zones', $timezone['staged_label']);
        $this->assertNull($timezone['label_ciphertext']);
        // Nothing to decrypt it with yet: a record without key_ring_id is skipped by both decrypt scripts.
        $this->assertArrayNotHasKey('key_ring_id', $timezone);
    }

    public function test_the_browser_can_name_a_staged_field(): void
    {
        $user = User::factory()->create();
        $this->visit($this->connection($user), 'Asia/Tokyo', 'ja');
        VisitorFieldsBackfill::forUser($user);
        $field = $this->fieldOf($user, 'timezone');

        $this->actingAs($user)->patchJson("/dashboard/connection-attribute-definitions/{$field->id}", ['label_ciphertext' => 'encrypted-label'])->assertOk();

        $this->assertSame('encrypted-label', $field->fresh()->label_ciphertext);
        // The encrypted label replaces the staged one.
        $this->assertNull($field->fresh()->staged_label);
    }

    public function test_language_and_timezone_values_are_saved_as_plaintext_lists_and_nothing_else(): void
    {
        $user = User::factory()->create();
        $a = $this->connection($user);

        foreach (['language' => ['en', 'hu'], 'timezone' => ['Europe/Budapest']] as $type => $entries) {
            $field = $user->connectionAttributeDefinitions()->create(['id' => (string) Str::uuid(), 'label_ciphertext' => 'l', 'type' => $type, 'sort_order' => 1]);

            $this->actingAs($user)->patchJson("/dashboard/connections/{$a->id}", ['attribute_values' => [['attribute_definition_id' => $field->id, 'identifier_list_value' => $entries]]])->assertOk();
            $this->assertSame($entries, ConnectionAttributeValue::where('attribute_definition_id', $field->id)->firstOrFail()->identifier_list_value);

            // The E2EE column is the wrong one for these types.
            $this->actingAs($user)->patchJson("/dashboard/connections/{$a->id}", ['attribute_values' => [['attribute_definition_id' => $field->id, 'value_ciphertext' => 'x']]])->assertUnprocessable();
        }
    }

    public function test_language_and_timezone_definitions_can_be_created(): void
    {
        $user = User::factory()->create();

        foreach (['language', 'timezone'] as $type) {
            $this->actingAs($user)->postJson('/dashboard/connection-attribute-definitions', [
                'id' => (string) Str::uuid(), 'label_ciphertext' => 'opaque', 'type' => $type,
            ])->assertCreated();
        }
    }
}
