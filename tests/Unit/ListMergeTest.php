<?php

namespace Tests\Unit;

use App\Support\ListMerge;
use PHPUnit\Framework\TestCase;

class ListMergeTest extends TestCase
{
    public function test_without_concurrent_changes_it_is_just_what_was_submitted(): void
    {
        $this->assertSame(['b', 'c'], ListMerge::threeWay(['a', 'b'], ['a', 'b'], ['b', 'c']));
    }

    public function test_entries_added_since_the_page_loaded_survive(): void
    {
        // The server added "Alice" after the page loaded; the owner only added "Mom".
        $this->assertSame(['Dad', 'Mom', 'Alice'], ListMerge::threeWay(['Dad', 'Alice'], ['Dad'], ['Dad', 'Mom']));
    }

    public function test_an_entry_the_owner_deleted_stays_deleted_even_though_the_server_still_has_it(): void
    {
        $this->assertSame(['Dad'], ListMerge::threeWay(['Dad', 'Old name'], ['Dad', 'Old name'], ['Dad']));
    }

    public function test_deleting_everything_still_keeps_what_was_added_meanwhile(): void
    {
        $this->assertSame(['New'], ListMerge::threeWay(['Old', 'New'], ['Old'], []));
        $this->assertSame([], ListMerge::threeWay(['Old'], ['Old'], []));
    }

    public function test_a_page_that_had_nothing_does_not_wipe_what_appeared(): void
    {
        $this->assertSame(['Alice'], ListMerge::threeWay(['Alice'], [], []));
    }

    public function test_it_compares_ignoring_case_and_space_and_dedupes_keeping_the_first_spelling(): void
    {
        $this->assertSame(['alice', 'Bob'], ListMerge::threeWay([' ALICE ', 'bob', 'BOB'], ['alice'], ['alice', 'Bob', ' ']));
    }
}
