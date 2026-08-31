<?php

namespace Tests\Unit\Calendar;

use App\Services\Calendar\ActivityExtractor;
use PHPUnit\Framework\TestCase;

class ActivityExtractorTest extends TestCase
{
    private ActivityExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new ActivityExtractor();
    }

    public function test_extracts_the_freetext_before_with(): void
    {
        $this->assertSame('Dinner', $this->extractor->extract('Dinner with Alice'));
    }

    public function test_extracts_the_freetext_before_w_slash(): void
    {
        $this->assertSame('Coffee', $this->extractor->extract('Coffee w/ Bob'));
    }

    public function test_returns_null_when_there_is_no_with_clause(): void
    {
        $this->assertNull($this->extractor->extract('Team sync'));
    }

    public function test_returns_null_when_the_activity_prefix_is_empty(): void
    {
        $this->assertNull($this->extractor->extract('with Alice'));
    }

    public function test_trims_trailing_whitespace(): void
    {
        $this->assertSame('Dinner', $this->extractor->extract('Dinner   with Alice'));
    }

    public function test_a_custom_pattern_overrides_the_default(): void
    {
        $this->assertSame('Focus session', $this->extractor->extract('Focus session w: Bob', '^(.*?)\bw:'));
    }

    public function test_an_invalid_custom_pattern_fails_closed_instead_of_throwing(): void
    {
        $this->assertNull($this->extractor->extract('Dinner with Alice', '('));
    }
}
