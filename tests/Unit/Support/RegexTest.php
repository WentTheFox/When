<?php

namespace Tests\Unit\Support;

use App\Support\Regex;
use PHPUnit\Framework\TestCase;

class RegexTest extends TestCase
{
    public function test_try_split_splits_on_a_pattern(): void
    {
        $this->assertSame(['Alice', 'Bob'], Regex::trySplit("\x01, \x01iu", 'Alice, Bob'));
    }

    public function test_try_split_returns_null_for_an_invalid_pattern(): void
    {
        $this->assertNull(Regex::trySplit("\x01(unterminated\x01iu", 'Alice, Bob'));
    }

    public function test_counts_a_single_capture_group(): void
    {
        $this->assertSame(1, Regex::countCaptureGroups('\b(?:with|w\/)\s+(.+)$'));
    }

    public function test_counts_zero_capture_groups(): void
    {
        $this->assertSame(0, Regex::countCaptureGroups('no groups here'));
    }

    public function test_counts_multiple_capture_groups(): void
    {
        $this->assertSame(2, Regex::countCaptureGroups('(one)(two)'));
    }

    public function test_does_not_count_a_non_capturing_group(): void
    {
        $this->assertSame(0, Regex::countCaptureGroups('(?:not counted)'));
    }

    /**
     * A named group appears in PHP's own $matches array under BOTH its
     * numeric key and its name (e.g. group 1 named "foo" shows up as both
     * [1] and ['foo']) — countCaptureGroups must only count the numeric
     * keys, or it would double-count every named group.
     */
    public function test_counts_a_named_group_once_not_twice(): void
    {
        $this->assertSame(1, Regex::countCaptureGroups('(?:non capturing) (?<name>named)'));
        $this->assertSame(1, Regex::countCaptureGroups('(?P<name>named)'));
        $this->assertSame(1, Regex::countCaptureGroups("(?'name'named)"));
    }

    public function test_counts_nested_capture_groups(): void
    {
        $this->assertSame(2, Regex::countCaptureGroups('(a|(b))'));
    }

    public function test_returns_null_for_an_invalid_pattern(): void
    {
        $this->assertNull(Regex::countCaptureGroups('(unterminated'));
    }

    public function test_counts_zero_groups_for_an_empty_pattern(): void
    {
        $this->assertSame(0, Regex::countCaptureGroups(''));
    }
}
