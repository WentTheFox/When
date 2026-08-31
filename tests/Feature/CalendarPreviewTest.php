<?php

namespace Tests\Feature;

use App\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CalendarPreviewTest extends TestCase
{
    use RefreshDatabase;

    private function icsFixture(): string
    {
        $eventDate = now()->addDays(2)->format('Ymd');

        return <<<ICS
        BEGIN:VCALENDAR
        VERSION:2.0
        PRODID:-//WhenTheFox Test Fixtures//EN
        BEGIN:VEVENT
        UID:preview-1@example.com
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

    public function test_an_authenticated_owner_can_preview_without_saving_anything(): void
    {
        $this->mockCalendarResponse($this->icsFixture());
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/settings/calendar/preview', [
            'calendar_url' => 'https://example.com/preview.ics',
            'highlight_words' => ['Alice'],
        ]);

        $response->assertOk();
        $response->assertJsonPath('detected_mode', 'full_detail');
        $highlighted = $response->json('highlighted');
        $this->assertCount(1, $highlighted);
        $this->assertSame(['Alice'], $highlighted[0]['highlight_words']);
        // The same event is also present in unavailable — highlighted and
        // unavailable can legitimately overlap (AvailabilityResult).
        $this->assertCount(1, $response->json('unavailable'));

        // Nothing persisted: no cache, no detection history, no stored URL anywhere.
        $this->assertDatabaseCount('share_link_cache', 0);
        $this->assertDatabaseCount('calendar_detections', 0);
        $user->refresh();
        $this->assertNull($user->calendar_url_ciphertext);
    }

    public function test_an_unauthenticated_visitor_cannot_use_the_preview_endpoint(): void
    {
        $response = $this->postJson('/settings/calendar/preview', [
            'calendar_url' => 'https://example.com/preview.ics',
        ]);

        $response->assertStatus(401);
    }

    public function test_rejects_a_non_url_calendar_url(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/settings/calendar/preview', [
            'calendar_url' => 'not-a-url',
        ]);

        $response->assertStatus(422);
    }
}
