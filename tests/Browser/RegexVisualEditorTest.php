<?php

namespace Tests\Browser;

use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Live-browser coverage for the Scratch-style visual regex block editor
 * (resources/js/dashboard/regex-editor/*) — the parts that genuinely need a
 * real browser (Vue reactivity, vuedraggable/SortableJS, the BModal
 * lifecycle) rather than a unit test of regexAstModel.ts in isolation.
 *
 * Deliberately no DatabaseMigrations here — whenthefox_test's schema is
 * already fully migrated (shared with plain `php artisan test` runs), and
 * one of the existing migrations' down() doesn't round-trip cleanly under
 * Postgres, which DatabaseMigrations's per-test migrate/rollback cycle
 * would hit on every single test. Each test creates its own factory user
 * instead (unique name/email per Faker), so tests don't collide.
 */
class RegexVisualEditorTest extends DuskTestCase
{
    /** CSS selector for the "open visual editor" button that belongs to one specific field, disambiguated from the other 11 identical buttons via :has() on its shared wrapper. */
    private function visualEditorButtonFor(string $fieldId): string
    {
        return '.wtf-regex-input-group:has(#'.$fieldId.') button';
    }

    public function test_existing_pattern_loads_into_correct_blocks_and_applies_edits_back_to_the_textarea(): void
    {
        $user = User::factory()->create([
            // Exercises every block type in the requested scope in one
            // pattern: a literal run, a capture group, a custom character
            // class with a quantifier, and an end anchor.
            'highlight_clause_pattern' => 'with ([\w, ]+)$',
        ]);

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/settings')
                ->waitFor('#highlight_clause_pattern')
                ->assertInputValue('#highlight_clause_pattern', 'with ([\w, ]+)$')
                ->click($this->visualEditorButtonFor('highlight_clause_pattern'))
                ->waitFor('.wtf-regex-editor-modal')
                ->assertSee('Capture group')
                ->assertSee('Any of')
                ->assertSeeIn('.wtf-regex-editor-canvas .wtf-regex-block-anchor', 'End of text')
                ->assertInputValue('.wtf-regex-block-literal input.wtf-regex-block-text', 'with ')
                ->assertInputValue('.wtf-regex-block-charClass input.wtf-regex-block-text', '\w, ')
                ->assertSeeIn('.wtf-regex-editor-modal .wtf-pattern-preview-panel code', 'with ([\w, ]+)$')
                // Edit the literal block's text — the live pattern preview
                // (and, on Apply, the real textarea) should reflect it
                // immediately, proving the block tree round-trips through
                // serializeAst() correctly, not just on initial load.
                ->clear('.wtf-regex-block-literal input.wtf-regex-block-text')
                ->type('.wtf-regex-block-literal input.wtf-regex-block-text', 'attending ')
                ->waitUsing(5, 100, function () use ($browser) {
                    return $browser->text('.wtf-regex-editor-modal .wtf-pattern-preview-panel code') === 'attending ([\w, ]+)$';
                }, 'the live pattern preview to reflect the edited literal block')
                ->press('Apply')
                ->waitUntilMissing('.wtf-regex-editor-modal')
                ->assertInputValue('#highlight_clause_pattern', 'attending ([\w, ]+)$');
        });
    }

    public function test_cancel_leaves_the_original_pattern_untouched(): void
    {
        $user = User::factory()->create([
            'dnd_event_pattern' => 'do.not.disturb',
        ]);

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/settings')
                ->waitFor('#dnd_event_pattern')
                ->click($this->visualEditorButtonFor('dnd_event_pattern'))
                ->waitFor('.wtf-regex-editor-modal')
                ->clear('.wtf-regex-block-literal input.wtf-regex-block-text')
                ->type('.wtf-regex-block-literal input.wtf-regex-block-text', 'changed')
                ->press('Cancel')
                ->waitUntilMissing('.wtf-regex-editor-modal')
                ->assertInputValue('#dnd_event_pattern', 'do.not.disturb');
        });
    }

    public function test_the_modal_preview_uses_the_same_examples_as_the_fields_own_preview(): void
    {
        $user = User::factory()->create([
            'dnd_event_pattern' => 'dnd',
        ]);

        // dnd_event_pattern's own hardcoded example lines (see
        // dndPreviewConfig in SettingsEventMatchingCard.vue) — the modal's
        // preview must be seeded from these same lines, not a generic
        // fallback sample.
        $expectedExamples = "DND\nTeam DND block\ndnd - focus time\nFocus time\nLunch with Sarah";

        $this->browse(function (Browser $browser) use ($user, $expectedExamples) {
            $browser->loginAs($user)
                ->visit('/settings')
                ->waitFor('#dnd_event_pattern')
                ->assertInputValue('.wtf-pattern-preview-native', $expectedExamples)
                ->click($this->visualEditorButtonFor('dnd_event_pattern'))
                ->waitFor('.wtf-regex-editor-modal')
                ->assertInputValue('.wtf-regex-editor-modal .wtf-pattern-preview-native', $expectedExamples);
        });
    }

    public function test_an_unsupported_construct_round_trips_as_a_raw_block(): void
    {
        $user = User::factory()->create([
            // A lookahead has no visual-block form (see regexAstModel.ts) —
            // must survive the round trip byte-for-byte via the raw block,
            // not be dropped or corrupted.
            'dnd_event_pattern' => 'foo(?=bar)',
        ]);

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/settings')
                ->waitFor('#dnd_event_pattern')
                ->click($this->visualEditorButtonFor('dnd_event_pattern'))
                ->waitFor('.wtf-regex-editor-modal')
                ->assertSee('Raw regex')
                ->assertInputValue('.wtf-regex-block-raw input.wtf-regex-block-raw-text', '(?=bar)')
                ->assertInputValue('.wtf-regex-block-literal input.wtf-regex-block-text', 'foo')
                ->press('Apply')
                ->waitUntilMissing('.wtf-regex-editor-modal')
                ->assertInputValue('#dnd_event_pattern', 'foo(?=bar)');
        });
    }
}
