<?php

namespace Tests\Feature;

use App\Jobs\RecomputeShareLinkAvailability;
use App\Models\ShareLink;
use App\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/**
 * StageTimer's diagnostic timing (see config/logging.php's "availability"
 * channel) — confirms the logs actually get written with a trace_id
 * grouping every stage of one run, and confirms the same "never log
 * plaintext" discipline CalendarUrlNeverLoggedTest enforces on the failure
 * path also holds here on the success path (every stage's context is ids/
 * counts/mode labels only).
 */
class AvailabilityTimingLoggingTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET_CALENDAR_URL = 'https://calendar.example.com/timing-test-token-5566.ics';

    private function icsFixture(): string
    {
        $eventDate = now()->addDays(2)->format('Ymd');

        return <<<ICS
        BEGIN:VCALENDAR
        VERSION:2.0
        PRODID:-//When Test Fixtures//EN
        BEGIN:VEVENT
        UID:timing-1@example.com
        DTSTAMP:{$eventDate}T000000Z
        DTSTART:{$eventDate}T120000Z
        DTEND:{$eventDate}T130000Z
        SUMMARY:Coffee with Alice
        END:VEVENT
        END:VCALENDAR
        ICS;
    }

    private function mockCalendarResponse(string $icsBody): void
    {
        $mock = new MockHandler([new Response(200, [], $icsBody)]);
        $this->app->bind(Client::class, fn () => new Client(['handler' => HandlerStack::create($mock)]));
    }

    private function useScratchLogFile(): string
    {
        $logPath = storage_path('logs/availability-timing-test.log');
        @unlink($logPath);
        config(['logging.channels.availability.path' => $logPath]);

        return $logPath;
    }

    public function test_preview_request_logs_a_trace_id_grouped_stage_timeline_with_no_plaintext(): void
    {
        $logPath = $this->useScratchLogFile();
        $this->mockCalendarResponse($this->icsFixture());

        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/settings/calendar/preview', [
            'calendar_url' => self::SECRET_CALENDAR_URL,
            'highlight_words' => ['Alice'],
        ])->assertOk();

        $this->assertFileExists($logPath);
        $log = file_get_contents($logPath);

        $this->assertStringContainsString('availability_preview.fetch', $log);
        $this->assertStringContainsString('availability_preview.parse_and_classify', $log);
        $this->assertStringContainsString('availability_preview.normalize', $log);
        $this->assertStringContainsString('availability_preview.compute_availability', $log);
        $this->assertStringContainsString('"trace_id"', $log);
        $this->assertStringContainsString('"duration_ms"', $log);

        // Every stage line shares the same trace_id.
        preg_match_all('/"trace_id":"([^"]+)"/', $log, $matches);
        $this->assertNotEmpty($matches[1]);
        $this->assertCount(1, array_unique($matches[1]));

        $this->assertStringNotContainsString(self::SECRET_CALENDAR_URL, $log);
        $this->assertStringNotContainsString('Coffee with Alice', $log);
        $this->assertStringNotContainsString('Alice', $log);

        @unlink($logPath);
    }

    public function test_recompute_job_logs_a_trace_id_grouped_stage_timeline_with_no_plaintext(): void
    {
        $logPath = $this->useScratchLogFile();
        $this->mockCalendarResponse($this->icsFixture());

        $user = User::factory()->create([
            'calendar_url_ciphertext' => Crypt::encryptString(self::SECRET_CALENDAR_URL),
        ]);
        $shareLink = ShareLink::factory()->for($user)->create();

        RecomputeShareLinkAvailability::dispatchSync($shareLink->id);

        $this->assertFileExists($logPath);
        $log = file_get_contents($logPath);

        $this->assertStringContainsString('availability_recompute.fetch', $log);
        $this->assertStringContainsString('availability_recompute.parse', $log);
        $this->assertStringContainsString('availability_recompute.classify', $log);
        $this->assertStringContainsString('availability_recompute.normalize', $log);
        $this->assertStringContainsString('availability_recompute.compute_availability', $log);
        $this->assertStringContainsString('availability_recompute.encrypt_and_store', $log);
        $this->assertStringContainsString((string) $shareLink->id, $log);

        preg_match_all('/"trace_id":"([^"]+)"/', $log, $matches);
        $this->assertNotEmpty($matches[1]);
        $this->assertCount(1, array_unique($matches[1]));

        $this->assertStringNotContainsString(self::SECRET_CALENDAR_URL, $log);

        @unlink($logPath);
    }
}
