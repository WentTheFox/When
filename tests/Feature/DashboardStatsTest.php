<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\ShareLink;
use App\Models\ShareLinkWord;
use App\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardStatsTest extends TestCase
{
    use RefreshDatabase;

    private function icsFixture(): string
    {
        $today = now('UTC')->format('Ymd');

        return <<<ICS
        BEGIN:VCALENDAR
        VERSION:2.0
        PRODID:-//WhenTheFox Test Fixtures//EN
        BEGIN:VEVENT
        UID:work-1@example.com
        DTSTAMP:{$today}T000000Z
        DTSTART:{$today}T090000Z
        DTEND:{$today}T110000Z
        SUMMARY:Work block
        END:VEVENT
        BEGIN:VEVENT
        UID:alice-1@example.com
        DTSTAMP:{$today}T000000Z
        DTSTART:{$today}T140000Z
        DTEND:{$today}T150000Z
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

    public function test_returns_no_calendar_error_when_the_owner_has_no_calendar_url(): void
    {
        $user = User::factory()->create(['calendar_url_ciphertext' => null]);

        $response = $this->actingAs($user)->getJson('/dashboard/stats/availability');

        $response->assertOk();
        $response->assertJsonPath('error', 'no_calendar');
    }

    public function test_an_unauthenticated_visitor_cannot_use_the_stats_endpoint(): void
    {
        $response = $this->getJson('/dashboard/stats/availability');

        $response->assertStatus(401);
    }

    public function test_breakdown_rows_classify_a_matching_event_as_work(): void
    {
        $this->mockCalendarResponse($this->icsFixture());

        $user = User::factory()->create([
            'calendar_url_ciphertext' => Crypt::encryptString('https://example.com/secret.ics'),
            'timezone' => 'UTC',
            'work_event_name' => 'Work',
            'calendar_parsing_mode' => 'full_detail',
        ]);

        $response = $this->actingAs($user)->getJson('/dashboard/stats/availability');

        $response->assertOk();
        $rows = $response->json('rows');
        $this->assertSame(['Today', 'This week', 'Past 30 days'], array_column($rows, 'title'));

        $today = $rows[0];
        $this->assertFalse($today['notAvail']);
        $this->assertSame('2:00', $today['workLabel']);
        $this->assertGreaterThan(0, $today['workPct']);
    }

    public function test_a_share_link_with_words_appears_in_the_highlights_leaderboard_labeled_by_its_linked_connection(): void
    {
        $this->mockCalendarResponse($this->icsFixture());

        $user = User::factory()->create([
            'calendar_url_ciphertext' => Crypt::encryptString('https://example.com/secret.ics'),
            'timezone' => 'UTC',
            'calendar_parsing_mode' => 'full_detail',
        ]);

        $shareLink = ShareLink::factory()->for($user)->create();
        ShareLinkWord::create([
            'share_link_id' => $shareLink->id,
            'word_ciphertext' => Crypt::encryptString('Alice'),
        ]);

        $connection = Connection::create([
            'id' => Str::uuid(),
            'user_id' => $user->id,
            'name_ciphertext' => 'opaque-ciphertext-blob',
            'share_link_id' => $shareLink->id,
        ]);

        $response = $this->actingAs($user)->getJson('/dashboard/stats/availability');

        $response->assertOk();
        $highlights = $response->json('highlights');
        $this->assertCount(1, $highlights);
        $this->assertSame($shareLink->id, $highlights[0]['share_link_id']);
        $this->assertSame((string) $connection->id, $highlights[0]['connection']['id']);
        $this->assertSame('opaque-ciphertext-blob', $highlights[0]['connection']['name_ciphertext']);
        $this->assertGreaterThan(0, $highlights[0]['minutes']);

        // Never a plaintext label — only ciphertext/ids cross the wire.
        $this->assertArrayNotHasKey('label', $highlights[0]);
    }

    public function test_a_share_link_with_no_words_is_excluded_from_the_leaderboard(): void
    {
        $this->mockCalendarResponse($this->icsFixture());

        $user = User::factory()->create([
            'calendar_url_ciphertext' => Crypt::encryptString('https://example.com/secret.ics'),
            'timezone' => 'UTC',
            'calendar_parsing_mode' => 'full_detail',
        ]);

        ShareLink::factory()->for($user)->create();

        $response = $this->actingAs($user)->getJson('/dashboard/stats/availability');

        $response->assertOk();
        $this->assertCount(0, $response->json('highlights'));
    }
}
