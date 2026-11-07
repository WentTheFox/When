<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Sabre\VObject\Reader;
use Tests\TestCase;

class DevMockCalendarTest extends TestCase
{
    public function test_the_mock_feed_is_a_valid_calendar_that_covers_the_coming_weeks(): void
    {
        $response = $this->get('/dev/mock-calendar.ics');

        $response->assertOk();
        $this->assertStringStartsWith('text/calendar', (string) $response->headers->get('Content-Type'));

        $calendar = Reader::read($response->getContent());
        $expanded = $calendar->expand(CarbonImmutable::now()->subDay()->toDateTimeImmutable(), CarbonImmutable::now()->addDays(30)->toDateTimeImmutable());

        $this->assertGreaterThan(20, count($expanded->VEVENT), 'the feed should have events in the next 30 days');
    }

    public function test_the_route_is_not_registered_in_production(): void
    {
        $this->assertTrue(app('router')->has('dev.mock-calendar'));

        // routes/web.php guards the registration on app()->isProduction(); assert the guard exists so a refactor can't drop it.
        $this->assertStringContainsString('! app()->isProduction()', (string) file_get_contents(base_path('routes/web.php')));
    }
}
