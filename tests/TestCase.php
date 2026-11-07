<?php

namespace Tests;

use App\Services\Calendar\CalendarUrlGuard;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // No real DNS in tests: every hostname resolves to a public (documentation-adjacent) address.
        $this->app->bind(CalendarUrlGuard::class, fn () => new CalendarUrlGuard(fn () => ['93.184.216.34'], false));
    }
}
