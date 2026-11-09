<?php

namespace Tests\Fixtures;

/**
 * How the browser test (Tests\Browser\CustomFieldTypesTest) edits one field of every custom field type, keyed by
 * type. `label` is the field's name in ConnectionsDemoSeeder (which holds one field per type).
 *
 * `kind` is the control:
 *   text    an <input> / <textarea>; `input` is typed in (one string; "\n" separates lines of a multi-line field)
 *   radio   a radio group; `input` is the choice's label
 *   search  a single-value searchable dropdown; `input` is the search text, picked with Enter
 *   pick    the same dropdown, picking the option at position `input` (0-based; used where option names are random)
 *   multi   a multi-value searchable dropdown; `input` is a list of search texts, each picked with Enter
 *   pickn   the same, picking the first `input` options (random names)
 * `expect` is what must show after saving and reloading: the field's value (text / radio) or text its dropdown
 * button must contain (search / pick / multi / pickn), as a list for the last two.
 *
 * ADDING A CUSTOM FIELD TYPE: add the type to ConnectionAttributeDefinition::TYPES, add a field of it to
 * ConnectionsDemoSeeder::createDefinitions(), and add an entry here. CustomFieldTypeCoverageTest fails until all three agree.
 */
final class CustomFieldTypeFixtures
{
    /** @return array<string, array{label: string, kind: string, input: string|int|list<string>, expect: string|list<string>}> */
    public static function all(): array
    {
        return [
            'text' => ['label' => 'Pronouns', 'kind' => 'text', 'input' => 'ze/zir', 'expect' => 'ze/zir'],
            'textarea' => ['label' => 'Notes', 'kind' => 'text', 'input' => "First line\nSecond line", 'expect' => "First line\nSecond line"],
            'date' => ['label' => 'Birthday', 'kind' => 'text', 'input' => '1990-05-17', 'expect' => '1990-05-17'],
            'number' => ['label' => 'Lucky number', 'kind' => 'text', 'input' => '42', 'expect' => '42'],
            'url' => ['label' => 'Website', 'kind' => 'text', 'input' => 'https://e2e.example.com/path', 'expect' => 'https://e2e.example.com/path'],
            'email' => ['label' => 'Email', 'kind' => 'text', 'input' => 'e2e@example.com', 'expect' => 'e2e@example.com'],
            'phone' => ['label' => 'Phone', 'kind' => 'text', 'input' => '+36 30 123 4567', 'expect' => '+36 30 123 4567'],
            'radio' => ['label' => 'Closeness', 'kind' => 'radio', 'input' => 'Close friend', 'expect' => 'Close friend'],
            'country' => ['label' => 'Country', 'kind' => 'search', 'input' => 'Hungary', 'expect' => 'Hungary'],
            'language' => ['label' => 'Languages', 'kind' => 'multi', 'input' => ['German', 'Japanese'], 'expect' => ['German', 'Japanese']],
            'timezone' => ['label' => 'Time zones', 'kind' => 'multi', 'input' => ['Europe/Budapest', 'Asia/Tokyo'], 'expect' => ['Europe/Budapest', 'Asia/Tokyo']],
            'list' => ['label' => 'Interests', 'kind' => 'text', 'input' => "Alpha\nBeta", 'expect' => "Alpha\nBeta"],
            'link_list' => ['label' => 'Links', 'kind' => 'text', 'input' => "https://one.example.com\nhttps://two.example.com", 'expect' => "https://one.example.com\nhttps://two.example.com"],
            'identifier' => ['label' => 'Handle', 'kind' => 'text', 'input' => 'e2e_handle', 'expect' => 'e2e_handle'],
            'identifier_list' => ['label' => 'Highlight words', 'kind' => 'text', 'input' => "alpha\nbeta", 'expect' => "alpha\nbeta"],
            'boolean' => ['label' => 'Bypass DND', 'kind' => 'radio', 'input' => 'Yes', 'expect' => 'Yes'],
            'connection' => ['label' => 'Introduced by', 'kind' => 'pick', 'input' => 1, 'expect' => '!(none)'],
            'relationship' => ['label' => 'Friends', 'kind' => 'pickn', 'input' => 2, 'expect' => '!Add a connection…'],
        ];
    }
}
