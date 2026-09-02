<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * highlight_clause_pattern/activity_clause_pattern are the only two
 * event-title patterns whose caller reads a *specific* capture group by
 * number (HighlightMatcher::matchClauseText / ActivityExtractor::extract
 * both read $matches[1]) — see App\Support\Regex::validateSingleCaptureGroup.
 */
class SettingsPatternValidationTest extends TestCase
{
    use RefreshDatabase;

    private function baseSettings(): array
    {
        return [
            'timezone' => 'UTC',
            'week_start' => 1,
            'calendar_parsing_mode' => 'full_detail',
        ];
    }

    public function test_a_highlight_pattern_with_no_capture_group_is_rejected(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->patch('/settings', [
            ...$this->baseSettings(),
            'highlight_clause_pattern' => 'with no groups',
        ]);

        $response->assertSessionHasErrors('highlight_clause_pattern');
    }

    public function test_a_highlight_pattern_with_two_capture_groups_is_rejected(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->patch('/settings', [
            ...$this->baseSettings(),
            'highlight_clause_pattern' => '(one)(two)',
        ]);

        $response->assertSessionHasErrors('highlight_clause_pattern');
    }

    public function test_a_highlight_pattern_with_exactly_one_capture_group_is_accepted(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->patch('/settings', [
            ...$this->baseSettings(),
            'highlight_clause_pattern' => '\b(?:with|w\/)\s+(.+)$',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame('\b(?:with|w\/)\s+(.+)$', $user->fresh()->highlight_clause_pattern);
    }

    public function test_a_non_capturing_group_does_not_count_toward_the_limit(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->patch('/settings', [
            ...$this->baseSettings(),
            'activity_clause_pattern' => '^(.*?)(?:with|w\/)',
        ]);

        $response->assertSessionHasNoErrors();
    }

    public function test_an_invalid_regex_is_rejected_with_a_clear_error(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->patch('/settings', [
            ...$this->baseSettings(),
            'activity_clause_pattern' => '(unterminated',
        ]);

        $response->assertSessionHasErrors('activity_clause_pattern');
    }

    public function test_a_blank_pattern_is_not_validated(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->patch('/settings', [
            ...$this->baseSettings(),
            'highlight_clause_pattern' => '',
            'activity_clause_pattern' => '',
        ]);

        $response->assertSessionHasNoErrors();
    }

    /**
     * dnd/nap/work event-name patterns are a plain boolean match — they
     * never read a capture group at all, so this validation must never
     * apply to them even though they're regex fragments too.
     */
    public function test_event_name_patterns_are_not_subject_to_the_capture_group_check(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->patch('/settings', [
            ...$this->baseSettings(),
            'dnd_event_name' => 'no groups, two (parens)(here) even',
        ]);

        $response->assertSessionHasNoErrors();
    }
}
