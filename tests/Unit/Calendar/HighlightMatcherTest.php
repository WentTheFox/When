<?php

namespace Tests\Unit\Calendar;

use App\Domain\Calendar\ParsedEvent;
use App\Services\Calendar\HighlightMatcher;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class HighlightMatcherTest extends TestCase
{
    private HighlightMatcher $matcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->matcher = new HighlightMatcher;
    }

    private function event(?string $summary = null, ?string $description = null, ?string $location = null, bool $isFreeBusyOnly = false): ParsedEvent
    {
        return new ParsedEvent(
            uid: 'x',
            start: CarbonImmutable::parse('2026-06-03 09:00', 'UTC'),
            end: CarbonImmutable::parse('2026-06-03 10:00', 'UTC'),
            summary: $summary,
            description: $description,
            location: $location,
            isFreeBusyOnly: $isFreeBusyOnly,
        );
    }

    public function test_matches_the_abbreviated_w_slash_clause(): void
    {
        $result = $this->matcher->match($this->event(summary: 'Dinner w/ Bob'), ['Bob']);
        $this->assertSame(['Bob'], $result->words);
        $this->assertFalse($result->visiting);
        $this->assertFalse($result->hosting);
    }

    public function test_clause_matching_is_case_insensitive_on_the_with_keyword(): void
    {
        // "WITH" (not "with"/"With") still triggers the clause — only the
        // "with"/"w/" keyword match is case-insensitive; the extracted
        // token still has to match the configured word's exact case (see
        // test_the_extracted_token_comparison_is_case_sensitive).
        $result = $this->matcher->match($this->event(summary: 'Dinner WITH Bob'), ['Bob']);
        $this->assertNotNull($result);
        $this->assertSame(['Bob'], $result->words);
    }

    /**
     * Unlike the "with"/"w/" keyword match itself, the extracted token vs
     * configured-word comparison is a case-sensitive substring check — this
     * mirrors the source app's own (slightly inconsistent) behavior
     * deliberately, see HighlightMatcher::matchTokens.
     */
    public function test_the_extracted_token_comparison_is_case_sensitive(): void
    {
        $result = $this->matcher->match($this->event(summary: 'Dinner with BOB'), ['Bob']);
        $this->assertNull($result);
    }

    public function test_falls_back_to_description_when_summary_has_no_clause(): void
    {
        $result = $this->matcher->match(
            $this->event(summary: 'Dinner', description: 'catching up with Carol'),
            ['Carol'],
        );
        $this->assertSame(['Carol'], $result->words);
    }

    public function test_never_reveals_the_raw_title_when_nothing_matches(): void
    {
        $result = $this->matcher->match($this->event(summary: 'Coffee with Some Stranger'), ['Alice']);
        $this->assertNull($result);
    }

    public function test_full_detail_event_can_still_fall_back_to_location_if_no_clause_matches(): void
    {
        $result = $this->matcher->match(
            $this->event(summary: 'Team sync', location: 'Alice'),
            ['Alice'],
        );
        $this->assertSame(['Alice'], $result->words);
    }

    public function test_a_custom_clause_pattern_overrides_the_built_in_with_w_slash_default(): void
    {
        $event = $this->event(summary: 'Focus session w: Bob');

        // The default pattern doesn't recognize "w:" — only "with"/"w/".
        $this->assertNull($this->matcher->match($event, ['Bob']));

        $result = $this->matcher->match($event, ['Bob'], 'w:\s+(.+)$');
        $this->assertSame(['Bob'], $result->words);
    }

    public function test_an_invalid_custom_clause_pattern_fails_closed_instead_of_throwing(): void
    {
        $event = $this->event(summary: 'Coffee with Alice');

        $result = $this->matcher->match($event, ['Alice'], '(');

        $this->assertNull($result);
    }

    /**
     * A clause can name more than one person — the default pattern captures
     * the whole "with X, Y" remainder, and each comma-separated token is
     * checked individually against the configured words.
     */
    public function test_a_second_comma_separated_name_still_matches(): void
    {
        $result = $this->matcher->match($this->event(summary: 'Dinner with Alice, Bob'), ['Bob']);
        $this->assertNotNull($result);
        $this->assertSame(['Bob'], $result->words);
    }

    /**
     * When more than one configured word matches, every one of them is
     * returned (in configured order) — not just the first, which used to
     * silently drop the rest.
     */
    public function test_every_matching_configured_word_is_returned_not_just_the_first(): void
    {
        $result = $this->matcher->match($this->event(summary: 'Dinner with Charlie, Alice'), ['Alice', 'Bob', 'Charlie']);
        $this->assertNotNull($result);
        $this->assertSame(['Alice', 'Charlie'], $result->words);
    }

    public function test_host_prefix_sets_visiting(): void
    {
        $result = $this->matcher->match($this->event(summary: 'Host Alice'), ['Alice']);
        $this->assertSame(['Alice'], $result->words);
        $this->assertTrue($result->visiting);
        $this->assertFalse($result->hosting);
    }

    public function test_visit_prefix_sets_hosting(): void
    {
        $result = $this->matcher->match($this->event(summary: 'Visit Alice'), ['Alice']);
        $this->assertSame(['Alice'], $result->words);
        $this->assertFalse($result->visiting);
        $this->assertTrue($result->hosting);
    }

    public function test_host_prefix_does_not_match_an_unconfigured_word(): void
    {
        $result = $this->matcher->match($this->event(summary: 'Host Someone Else'), ['Alice']);
        $this->assertNull($result);
    }

    /**
     * str_contains-based substring matching is permissive enough that
     * splitting rarely changes whether a single well-formed name matches
     * — the case where it actually matters is a configured word that
     * straddles two names' own boundary in the *unsplit* clause. Here
     * "ia, Bob" is a literal substring of the unsplit "Alicia, Bob", but
     * isn't a substring of either name once properly split into "Alicia"
     * and "Bob" — proving the default split pattern is actually being
     * applied, not just tolerated.
     */
    public function test_the_default_split_pattern_prevents_a_cross_boundary_false_match(): void
    {
        $result = $this->matcher->match($this->event(summary: 'Dinner with Alicia, Bob'), ['ia, Bob']);
        $this->assertNull($result);
    }

    public function test_the_default_split_pattern_also_splits_on_an_ampersand(): void
    {
        $result = $this->matcher->match($this->event(summary: 'Dinner with Alice & Bob'), ['Bob']);
        $this->assertNotNull($result);
        $this->assertSame(['Bob'], $result->words);
    }

    public function test_the_default_split_pattern_also_splits_on_a_slash(): void
    {
        $result = $this->matcher->match($this->event(summary: 'Dinner with Alice/Bob'), ['Bob']);
        $this->assertNotNull($result);
        $this->assertSame(['Bob'], $result->words);
    }

    public function test_an_owner_can_override_the_split_pattern_to_a_different_delimiter(): void
    {
        $result = $this->matcher->match($this->event(summary: 'Dinner with Alice; Bob'), ['Bob'], null, ';\s*');
        $this->assertNotNull($result);
        $this->assertSame(['Bob'], $result->words);
    }

    /**
     * An invalid split pattern fails closed to "the whole clause is one
     * token" rather than losing the match entirely. Reusing the previous
     * test's cross-boundary word ("ia, Bob" straddling "Alicia"/"Bob")
     * shows the practical effect of that fallback: with a genuinely broken
     * split pattern there's no way to correctly tokenize the clause at
     * all, so the single-token fallback is exactly what lets this
     * otherwise-nonsensical word match — a deliberate, known-safe
     * trade-off (never lose the match entirely) rather than a silent bug.
     */
    public function test_an_invalid_split_pattern_fails_closed_to_a_single_token(): void
    {
        $result = $this->matcher->match($this->event(summary: 'Dinner with Alicia, Bob'), ['ia, Bob'], null, '(unterminated');
        $this->assertNotNull($result);
        $this->assertSame(['ia, Bob'], $result->words);
    }
}
