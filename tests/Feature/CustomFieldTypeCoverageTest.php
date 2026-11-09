<?php

namespace Tests\Feature;

use App\Models\ConnectionAttributeDefinition;
use Database\Seeders\ConnectionsDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\Fixtures\CustomFieldTypeFixtures;
use Tests\TestCase;

/**
 * Adding a custom field type touches several places that can silently drift apart. This is the tripwire: a new type
 * must be in ConnectionAttributeDefinition::TYPES, allowed by the database constraint (its migration), present in the
 * demo seeder (so every renderer has data) and have a browser-test fixture (tests/Fixtures/CustomFieldTypeFixtures.php,
 * exercised by tests/Browser/CustomFieldTypesTest.php).
 */
class CustomFieldTypeCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_database_constraint_allows_exactly_the_declared_types(): void
    {
        $definition = (string) DB::selectOne("SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = 'connection_attribute_definitions_type_check'")->def;
        preg_match_all("/'([a-z_]+)'/", $definition, $matches);

        $this->assertEqualsCanonicalizing(ConnectionAttributeDefinition::TYPES, array_unique($matches[1]), 'The type constraint and ConnectionAttributeDefinition::TYPES disagree: a type needs a migration widening the constraint.');
    }

    public function test_every_type_has_a_browser_test_fixture(): void
    {
        $fixtures = array_keys(CustomFieldTypeFixtures::all());

        $this->assertEqualsCanonicalizing(ConnectionAttributeDefinition::TYPES, $fixtures, 'Every custom field type needs an entry in tests/Fixtures/CustomFieldTypeFixtures.php (and nothing else may be listed there).');
    }

    public function test_the_demo_seeder_has_a_field_for_every_fixture(): void
    {
        $source = (string) file_get_contents((new ReflectionMethod(ConnectionsDemoSeeder::class, 'createDefinitions'))->getFileName());

        foreach (CustomFieldTypeFixtures::all() as $type => $fixture) {
            $this->assertMatchesRegularExpression("/\\['".preg_quote($fixture['label'], '/')."', '".$type."'/", $source, "ConnectionsDemoSeeder has no \"{$fixture['label']}\" field of type {$type}.");
        }
    }
}
