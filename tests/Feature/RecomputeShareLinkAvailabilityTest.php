<?php

namespace Tests\Feature;

use App\Jobs\RecomputeShareLinkAvailability;
use App\Models\ShareLink;
use App\Models\ShareLinkWord;
use App\Models\User;
use App\Services\Crypto\AesGcm;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class RecomputeShareLinkAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private function icsFixture(): string
    {
        // The event must fall within the job's forward-looking compute
        // window (today .. +60 days), so anchor it to "now" rather than a
        // hardcoded date the perf-optimization would otherwise filter out.
        $eventDate = now()->addDays(3)->format('Ymd');

        return <<<ICS
        BEGIN:VCALENDAR
        VERSION:2.0
        PRODID:-//WhenTheFox Test Fixtures//EN
        BEGIN:VEVENT
        UID:e1@example.com
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
        $handlerStack = HandlerStack::create($mock);
        $this->app->bind(Client::class, fn () => new Client(['handler' => $handlerStack]));
    }

    public function test_recompute_stores_a_ciphertext_result_decryptable_with_the_share_links_content_key(): void
    {
        $this->mockCalendarResponse($this->icsFixture());

        $user = User::factory()->create([
            'calendar_url_ciphertext' => Crypt::encryptString('https://example.com/secret.ics'),
        ]);

        $rawContentKey = random_bytes(32);
        $shareLink = ShareLink::factory()->for($user)->create([
            'content_key_ciphertext' => Crypt::encryptString(base64_encode($rawContentKey)),
        ]);

        ShareLinkWord::create([
            'share_link_id' => $shareLink->id,
            'word_ciphertext' => Crypt::encryptString('Alice'),
        ]);

        RecomputeShareLinkAvailability::dispatchSync($shareLink->id);

        $shareLink->refresh();
        $cache = $shareLink->cache;

        $this->assertNotNull($cache);
        $this->assertStringNotContainsString('Alice', $cache->ciphertext);
        $this->assertStringNotContainsString('example.com/secret.ics', $cache->ciphertext);

        $decrypted = json_decode(AesGcm::decrypt($rawContentKey, $cache->ciphertext), true);

        $this->assertCount(1, $decrypted);
        $this->assertSame('highlighted', $decrypted[0]['type']);
        $this->assertSame('Alice', $decrypted[0]['highlight_word']);
    }

    public function test_recompute_records_a_calendar_detection(): void
    {
        $this->mockCalendarResponse($this->icsFixture());

        $user = User::factory()->create([
            'calendar_url_ciphertext' => Crypt::encryptString('https://example.com/secret.ics'),
        ]);
        $shareLink = ShareLink::factory()->for($user)->create([
            'content_key_ciphertext' => Crypt::encryptString(base64_encode(random_bytes(32))),
        ]);

        RecomputeShareLinkAvailability::dispatchSync($shareLink->id);

        $this->assertDatabaseHas('calendar_detections', [
            'user_id' => $user->id,
            'detected_mode' => 'full_detail',
        ]);
    }

    public function test_recompute_does_nothing_when_the_owner_has_no_calendar_url_set(): void
    {
        $this->mockCalendarResponse($this->icsFixture());

        $user = User::factory()->create(['calendar_url_ciphertext' => null]);
        $shareLink = ShareLink::factory()->for($user)->create([
            'content_key_ciphertext' => Crypt::encryptString(base64_encode(random_bytes(32))),
        ]);

        RecomputeShareLinkAvailability::dispatchSync($shareLink->id);

        $this->assertNull($shareLink->fresh()->cache);
    }
}
