<?php

namespace Tests\Feature;

use App\Jobs\RecomputeShareLinkAvailability;
use App\Models\ShareLink;
use App\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/**
 * §0.2's explicit regression test: greps logs for the plaintext calendar
 * URL after a fetch, specifically along the FAILURE path — Guzzle's own
 * exceptions embed the request URI in their message by default, and
 * Laravel's exception handler logs unhandled throwables, so this is where
 * a leak would actually show up if CalendarFetcher's sanitizing wrapper
 * regressed.
 */
class CalendarUrlNeverLoggedTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET_CALENDAR_URL = 'https://calendar.example.com/very-secret-token-9182734.ics';

    public function test_a_failed_fetch_never_leaks_the_calendar_url_into_the_log(): void
    {
        $logPath = storage_path('logs/plaintext-leak-test.log');
        @unlink($logPath);
        config([
            'logging.default' => 'leak-test',
            'logging.channels.leak-test' => ['driver' => 'single', 'path' => $logPath],
        ]);

        // A Guzzle ConnectException's message normally contains the request URI.
        $request = new Request('GET', self::SECRET_CALENDAR_URL);
        $connectException = new ConnectException(
            'cURL error: Could not resolve host: '.self::SECRET_CALENDAR_URL,
            $request,
        );
        $mock = new MockHandler([$connectException]);
        $this->app->bind(Client::class, fn () => new Client(['handler' => HandlerStack::create($mock)]));

        $user = User::factory()->create([
            'calendar_url_ciphertext' => Crypt::encryptString(self::SECRET_CALENDAR_URL),
        ]);
        $shareLink = ShareLink::factory()->for($user)->create();

        try {
            RecomputeShareLinkAvailability::dispatchSync($shareLink->id);
            $this->fail('Expected the job to throw when the calendar fetch fails.');
        } catch (\Throwable $e) {
            // Never contains the URL itself...
            $this->assertStringNotContainsString(self::SECRET_CALENDAR_URL, $e->getMessage());

            // ...and simulate what Laravel's default exception handler does
            // with an unhandled throwable from a failed job: log it.
            app(ExceptionHandler::class)->report($e);
        }

        if (file_exists($logPath)) {
            $logContents = file_get_contents($logPath);
            $this->assertStringNotContainsString(self::SECRET_CALENDAR_URL, $logContents);
        }

        @unlink($logPath);
    }
}
