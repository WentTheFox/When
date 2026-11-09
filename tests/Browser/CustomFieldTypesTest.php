<?php

namespace Tests\Browser;

use App\Models\Connection;
use App\Models\User;
use Database\Seeders\ConnectionsDemoSeeder;
use Illuminate\Support\Facades\Artisan;
use Laravel\Dusk\Browser;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\DuskTestCase;
use Tests\Fixtures\CustomFieldTypeFixtures;

/**
 * Live-browser coverage of every custom field type, end to end: the vault is unlocked for real (Argon2id + WebCrypto in
 * the browser), a value is entered through the field's actual control, saved (encrypted client-side), and after a
 * reload (decrypted again) the same value must be shown. One test per type, driven by CustomFieldTypeFixtures; a
 * new type fails CustomFieldTypeCoverageTest until it has a fixture, so it gets a test here too.
 *
 * Uses the demo account (ConnectionsDemoSeeder: login `demo`, master password `demo-password`), which holds one
 * field of every type. The seeder runs once per test run and replaces any previous demo account. Like the other
 * Browser tests it works on the already-migrated test database (no DatabaseMigrations).
 */
class CustomFieldTypesTest extends DuskTestCase
{
    private static bool $seeded = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$seeded) {
            Artisan::call('db:seed', ['--class' => ConnectionsDemoSeeder::class, '--force' => true]);
            self::$seeded = true;
        }
    }

    /** @return array<string, array{string}> */
    public static function types(): array
    {
        return collect(array_keys(CustomFieldTypeFixtures::all()))->mapWithKeys(fn (string $type) => [$type => [$type]])->all();
    }

    #[DataProvider('types')]
    public function test_a_value_of_each_type_survives_save_and_reload(string $type): void
    {
        $fixture = CustomFieldTypeFixtures::all()[$type];
        // `name` is encrypted, so it can't be queried in SQL.
        $user = User::all()->first(fn (User $u) => $u->name === ConnectionsDemoSeeder::DEMO_NAME) ?? $this->fail('The demo account was not seeded.');
        // The first connection by position that is not archived; each test edits it, so they build on one another harmlessly.
        $connection = Connection::where('user_id', $user->id)->where('archived', false)->orderBy('sort_order')->firstOrFail();

        $this->browse(function (Browser $browser) use ($user, $connection, $fixture) {
            $card = '#connection-'.$connection->id;
            $field = $card.' [data-field-label='.json_encode($fixture['label']).']';

            $browser->loginAs($user)->visit('/dashboard/connections#id='.$connection->id);
            $this->waitUntilDecrypted($browser, $card);

            if (! $this->exists($browser, $field)) {
                $this->addField($browser, $card, $fixture['label']);
            }
            $browser->waitFor($field);

            $this->enter($browser, $field, $fixture);

            $this->clickCardButton($browser, $card, 'Save');
            $browser->waitUsing(20, 250, fn () => $this->script($browser, 'return [...document.querySelectorAll('.json_encode($card.' button').')].some((b) => b.textContent.trim() === "Save" && b.disabled);') === true, 'the save to finish');
            $browser->assertMissing($card.' [role=alert]');

            // A reload decrypts everything again from the server's ciphertext.
            $browser->refresh();
            $this->waitUntilDecrypted($browser, $card);
            $browser->waitFor($field);

            $this->assertShows($browser, $field, $fixture);
        });
    }

    /** Waits for the connection's card to be on screen and decrypted, unlocking the vault whenever it is asked for. */
    private function waitUntilDecrypted(Browser $browser, string $card): void
    {
        $browser->waitFor($card, 30);
        $typed = false;

        $browser->waitUsing(90, 500, function () use ($browser, $card, &$typed) {
            $state = $this->script($browser, 'const card = document.querySelector('.json_encode($card).');
                const modal = document.querySelector("#vault-modal-passphrase");
                if (modal && modal.offsetParent !== null) return "modal";
                if (card && !card.querySelector(".encrypted-pending")) return "ready";
                const overlay = document.querySelector(".locked-overlay");
                if (overlay) { overlay.click(); }
                return "locked";');

            if ($state === 'modal' && ! $typed) {
                $browser->type('#vault-modal-passphrase', ConnectionsDemoSeeder::DEMO_PASSWORD)->keys('#vault-modal-passphrase', ['{enter}']);
                $typed = true;
            }

            return $state === 'ready';
        }, 'the vault to unlock and the connection to decrypt');
    }

    /** Opens the "Add a custom field…" picker and adds the field, for a connection that holds no value for it yet. */
    private function addField(Browser $browser, string $card, string $label): void
    {
        $root = $card.' .searchable-select:has(> button[aria-label="Custom field to add"])';
        $this->openDropdown($browser, $root);
        $this->searchAndPick($browser, $root, $label);
        $this->clickCardButton($browser, $card, 'Add field');
    }

    /** @param  array{kind: string, input: string|int|list<string>, expect: string|list<string>}  $fixture */
    private function enter(Browser $browser, string $field, array $fixture): void
    {
        $input = $fixture['input'];
        $dropdown = $field.' .searchable-select';

        switch ($fixture['kind']) {
            case 'text':
                // Set the way a paste would: the value, then the input event the form listens to.
                $this->script($browser, 'const el = document.querySelector('.json_encode($field).').querySelector("textarea, input");
                    const proto = el.tagName === "TEXTAREA" ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype;
                    Object.getOwnPropertyDescriptor(proto, "value").set.call(el, '.json_encode($input).');
                    el.dispatchEvent(new Event("input", { bubbles: true }));');
                break;
            case 'radio':
                $this->script($browser, 'const group = document.querySelector('.json_encode($field).');
                    const label = [...group.querySelectorAll("label")].find((l) => l.textContent.trim() === '.json_encode($input).');
                    label.click();');
                break;
            case 'search':
                $this->openDropdown($browser, $dropdown);
                $this->searchAndPick($browser, $dropdown, $input);
                break;
            case 'multi':
                $this->openDropdown($browser, $dropdown);
                $this->deselectAll($browser, $dropdown);
                foreach ($input as $query) {
                    $this->searchAndPick($browser, $dropdown, $query);
                }
                $this->closeDropdown($browser, $dropdown);
                break;
            case 'pick':
            case 'pickn':
                $this->openDropdown($browser, $dropdown);
                // The other connections' names are decrypted in the background after the unlock.
                $needed = $fixture['kind'] === 'pick' ? $input + 1 : $input;
                $browser->waitUsing(20, 250, fn () => $this->script($browser, 'return document.querySelectorAll('.json_encode($dropdown.' li[role=option]').').length;') >= $needed, 'the connection options to load');
                if ($fixture['kind'] === 'pickn') {
                    $this->deselectAll($browser, $dropdown);
                }
                foreach ($fixture['kind'] === 'pick' ? [$input] : range(0, $input - 1) as $position) {
                    $this->script($browser, 'document.querySelectorAll('.json_encode($dropdown.' li[role=option] button').')['.$position.'].click();');
                }
                $this->closeDropdown($browser, $dropdown);
                break;
        }
    }

    /** The dropdowns re-render as values are picked, so every step looks its elements up afresh instead of holding on to them. */
    private function openDropdown(Browser $browser, string $dropdown): void
    {
        $this->script($browser, 'document.querySelector('.json_encode($dropdown.' > button').').click();');
        $browser->waitFor($dropdown.' input[type=search]');
    }

    /** Picking toggles in a multi-value dropdown, and the demo data may already hold some of the values: start from empty, one pick at a time (each waits for the list to settle, like a person would). */
    private function deselectAll(Browser $browser, string $dropdown): void
    {
        $selected = $dropdown.' li[aria-selected=true] button';
        $count = fn () => (int) $this->script($browser, 'return document.querySelectorAll('.json_encode($selected).').length;');

        for ($remaining = $count(); $remaining > 0; $remaining = $count()) {
            $this->script($browser, 'document.querySelector('.json_encode($selected).').click();');
            $browser->waitUsing(5, 50, fn () => $count() < $remaining, 'a deselected option to leave the selection');
        }
    }

    private function closeDropdown(Browser $browser, string $dropdown): void
    {
        $this->script($browser, 'document.querySelector('.json_encode($dropdown).').dispatchEvent(new KeyboardEvent("keydown", { key: "Escape", bubbles: true }));');
    }

    /** Types into the open dropdown's search box, waits for the list to narrow to the match, and clicks it. */
    private function searchAndPick(Browser $browser, string $dropdown, string $query): void
    {
        $this->script($browser, 'const input = document.querySelector('.json_encode($dropdown.' input[type=search]').');
            input.value = '.json_encode($query).';
            input.dispatchEvent(new Event("input", { bubbles: true }));');

        $first = $dropdown.' li[role=option] button';
        $browser->waitUsing(10, 100, fn () => str_contains(strtolower((string) $this->script($browser, 'return (document.querySelector('.json_encode($first).') || {}).textContent || "";')), strtolower($query)), "the list to show \"{$query}\"");
        $this->script($browser, 'document.querySelector('.json_encode($first).').click();');
    }

    /** @param  array{kind: string, input: string|int|list<string>, expect: string|list<string>}  $fixture */
    private function assertShows(Browser $browser, string $field, array $fixture): void
    {
        $expect = $fixture['expect'];

        if (in_array($fixture['kind'], ['text', 'radio'], true)) {
            $shown = $fixture['kind'] === 'text'
                ? $this->script($browser, 'return document.querySelector('.json_encode($field).').querySelector("textarea, input").value;')
                : $this->script($browser, 'const radio = document.querySelector('.json_encode($field).').querySelector("input:checked");
                    return radio ? document.querySelector("label[for=" + radio.id + "]").textContent.trim() : null;');
            $this->assertSame($expect, $shown, 'The saved value did not come back after a reload.');

            return;
        }

        $shown = (string) $this->script($browser, 'return document.querySelector('.json_encode($field.' .searchable-select > button').').textContent.trim();');
        foreach ((array) $expect as $needle) {
            if (str_starts_with($needle, '!')) {
                $this->assertStringNotContainsString(substr($needle, 1), $shown);
            } else {
                $this->assertStringContainsString($needle, $shown);
            }
        }
    }

    private function exists(Browser $browser, string $selector): bool
    {
        return $this->script($browser, 'return document.querySelector('.json_encode($selector).') !== null;') === true;
    }

    private function clickCardButton(Browser $browser, string $card, string $text): void
    {
        $this->script($browser, 'const button = [...document.querySelectorAll('.json_encode($card.' button').')].find((b) => b.textContent.trim() === '.json_encode($text).'); button.click();');
    }

    private function script(Browser $browser, string $js): mixed
    {
        return $browser->script($js)[0] ?? null;
    }
}
