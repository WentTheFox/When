<?php

namespace App\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\Response;

/**
 * A fake owner calendar for local development and demos (ConnectionsDemoSeeder points the demo
 * account's calendar URL here), so the whole availability pipeline (fetch, parse, classify, compute,
 * encrypt, cache) can run without a real calendar provider.
 *
 * The events are generated relative to today, so the feed never goes stale: a mix of recurring
 * and one-off events, an all-day multi-day trip and a transparent (free) entry. Only registered
 * outside production (routes/web.php) and carries no data of any real person.
 */
class DevMockCalendarController extends Controller
{
    /** Weeks of history before this one, so the 30- and 90-day stats have something to chew on. */
    private const PAST_WEEKS = 13;

    private const WEEKS = 4;

    public function show(): Response
    {
        $thisMonday = CarbonImmutable::now('UTC')->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
        $monday = $thisMonday->subWeeks(self::PAST_WEEKS);

        $events = [];

        // Recurring: weekday stand-up and twice-weekly gym, starting 13 weeks ago.
        $events[] = $this->event('standup', 'Team standup', $monday->setTime(8, 0), $monday->setTime(8, 30), ['RRULE:FREQ=WEEKLY;BYDAY=MO,TU,WE,TH,FR']);
        $events[] = $this->event('gym', 'Gym', $monday->setTime(16, 30), $monday->setTime(17, 30), ['RRULE:FREQ=WEEKLY;BYDAY=TU,TH']);

        for ($week = 0; $week < self::PAST_WEEKS + self::WEEKS; $week++) {
            $w = $monday->addWeeks($week);

            $events[] = $this->event("dentist-$week", 'Dentist appointment', $w->addDays(1)->setTime(10, 0), $w->addDays(1)->setTime(11, 0));
            $events[] = $this->event("lunch-$week", 'Lunch with a friend', $w->addDays(2)->setTime(11, 30), $w->addDays(2)->setTime(13, 0));
            $events[] = $this->event("focus-$week", 'Focus block', $w->addDays(3)->setTime(13, 0), $w->addDays(3)->setTime(16, 0));
            $events[] = $this->event("games-$week", 'Game night', $w->addDays(4)->setTime(18, 0), $w->addDays(4)->setTime(22, 0));
            $events[] = $this->event("movie-$week", 'Movie night', $w->addDays(5)->setTime(17, 0), $w->addDays(5)->setTime(20, 0));

            // Shows up in the feed but doesn't block time.
            $events[] = $this->event("reminder-$week", 'Water the plants', $w->addDays(6)->setTime(9, 0), $w->addDays(6)->setTime(9, 15), ['TRANSP:TRANSPARENT']);
        }

        // An all-day, multi-day trip two weeks out (DTEND is exclusive for all-day events).
        $trip = $thisMonday->addWeeks(2)->addDays(2);
        $events[] = implode("\r\n", [
            'BEGIN:VEVENT',
            'UID:dev-trip@when.invalid',
            'DTSTAMP:'.$this->utc(CarbonImmutable::now('UTC')),
            'DTSTART;VALUE=DATE:'.$trip->format('Ymd'),
            'DTEND;VALUE=DATE:'.$trip->addDays(3)->format('Ymd'),
            'SUMMARY:Weekend trip',
            'END:VEVENT',
        ]);

        $body = implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//When//Dev mock calendar//EN',
            'CALSCALE:GREGORIAN',
            'X-WR-CALNAME:Demo calendar',
            ...$events,
            'END:VCALENDAR',
            '',
        ]);

        return response($body, 200, ['Content-Type' => 'text/calendar; charset=utf-8']);
    }

    /** @param list<string> $extra additional property lines (RRULE, TRANSP, ...) */
    private function event(string $uid, string $summary, CarbonImmutable $start, CarbonImmutable $end, array $extra = []): string
    {
        return implode("\r\n", [
            'BEGIN:VEVENT',
            "UID:dev-$uid@when.invalid",
            'DTSTAMP:'.$this->utc(CarbonImmutable::now('UTC')),
            'DTSTART:'.$this->utc($start),
            'DTEND:'.$this->utc($end),
            "SUMMARY:$summary",
            ...$extra,
            'END:VEVENT',
        ]);
    }

    private function utc(CarbonImmutable $time): string
    {
        return $time->utc()->format('Ymd\THis\Z');
    }
}
