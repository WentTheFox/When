<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Calendar\CalendarFetcher;
use App\Services\Calendar\CalendarFetchException;
use App\Services\Calendar\CalendarUrlGuard;
use App\Services\Calendar\UnsafeCalendarUrlException;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CalendarFetcherSsrfTest extends TestCase
{
    // The preview-endpoint test creates a user; without this it is committed and never removed.
    use RefreshDatabase;

    private function fetcher(array $responses): CalendarFetcher
    {
        $client = new Client(['handler' => HandlerStack::create(new MockHandler($responses))]);

        return new CalendarFetcher($client, $this->app->make(CalendarUrlGuard::class));
    }

    public function test_it_follows_a_redirect_to_a_public_host(): void
    {
        $body = $this->fetcher([
            new Response(302, ['Location' => 'https://cdn.example.com/real.ics']),
            new Response(200, [], 'BEGIN:VCALENDAR'),
        ])->fetch('https://example.com/calendar.ics');

        $this->assertSame('BEGIN:VCALENDAR', $body);
    }

    public function test_it_refuses_a_redirect_into_the_internal_network(): void
    {
        $this->expectException(UnsafeCalendarUrlException::class);

        $this->fetcher([
            new Response(302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
            new Response(200, [], 'secret'),
        ])->fetch('https://example.com/calendar.ics');
    }

    public function test_it_gives_up_after_too_many_redirects(): void
    {
        $this->expectException(CalendarFetchException::class);

        $this->fetcher(array_fill(0, 6, new Response(302, ['Location' => 'https://example.com/loop.ics'])))
            ->fetch('https://example.com/calendar.ics');
    }

    public function test_the_preview_endpoint_rejects_a_private_url(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patchJson('/settings/calendar-url', ['calendar_url' => 'http://127.0.0.1/a.ics', 'calendar_url_preview_confirmed' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors('calendar_url');
    }

    public function test_it_rejects_a_body_over_the_size_cap(): void
    {
        $this->expectException(CalendarFetchException::class);

        $this->fetcher([new Response(200, [], str_repeat('x', (int) config('services.calendar.max_body_bytes') + 1))])
            ->fetch('https://example.com/calendar.ics');
    }

    public function test_it_rejects_a_declared_content_length_over_the_cap_before_reading(): void
    {
        $this->expectException(CalendarFetchException::class);

        $this->fetcher([new Response(200, ['Content-Length' => (string) ((int) config('services.calendar.max_body_bytes') + 1)], 'x')])
            ->fetch('https://example.com/calendar.ics');
    }

    public function test_a_dual_stack_host_is_pinned_with_one_resolve_entry_holding_every_vetted_address(): void
    {
        // curl keeps only the last CURLOPT_RESOLVE entry per host:port, so one entry per address would pin
        // the host to a single (possibly IPv6) address. Shared by the calendar fetcher and the VRCX client.
        $guard = new CalendarUrlGuard(fn () => ['188.114.96.3', '188.114.97.3', '2a06:98c1:3121::3']);
        $target = $guard->inspect('https://dual.example.test/calendar.ics');

        $this->assertSame(
            ['dual.example.test:443:188.114.96.3,188.114.97.3,2a06:98c1:3121::3'],
            CalendarUrlGuard::curlResolveEntries($target),
        );
    }
}
