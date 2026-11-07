<?php

namespace Tests;

use App\Services\Calendar\CalendarUrlGuard;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Throwable;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // No real DNS in tests: every hostname resolves to a public (documentation-adjacent) address.
        $this->app->bind(CalendarUrlGuard::class, fn () => new CalendarUrlGuard(fn () => ['93.184.216.34'], false));
    }

    /**
     * A test that writes to the database without RefreshDatabase (or DatabaseTransactions) commits for good,
     * and every later test that assumes an empty users table then fails for no visible reason. Catch that here,
     * at the test that did it, rather than three files away. Only looks at connections the test actually opened.
     */
    protected function tearDown(): void
    {
        $leaked = false;

        if (isset($this->app)) {
            foreach ($this->app['db']->getConnections() as $connection) {
                try {
                    if ($connection->transactionLevel() === 0 && $connection->table('users')->exists()) {
                        $leaked = true;
                    }
                } catch (Throwable) {
                    // No users table (a schema-less test): nothing could have leaked into it.
                }
            }
        }

        parent::tearDown();

        if ($leaked) {
            $this->fail('This test left committed rows in the users table. Add the RefreshDatabase trait to it, then reset the test database with `php artisan migrate:fresh --env=testing`.');
        }
    }
}
