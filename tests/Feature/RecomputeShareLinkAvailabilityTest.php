<?php

namespace Tests\Feature;

use App\Jobs\RecomputeShareLinkAvailability;
use App\Models\ShareLink;
use App\Models\ShareLinkWord;
use App\Models\User;
use App\Services\Crypto\AesGcm;
use App\Services\Crypto\LegacyShareLinkKey;
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
        PRODID:-//When Test Fixtures//EN
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

    public function test_recompute_stores_a_ciphertext_result_decryptable_with_the_share_links_derived_content_key(): void
    {
        $this->mockCalendarResponse($this->icsFixture());

        $user = User::factory()->create([
            'calendar_url_ciphertext' => Crypt::encryptString('https://example.com/secret.ics'),
        ]);

        $shareLink = ShareLink::factory()->for($user)->create();

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

        $derivedKey = LegacyShareLinkKey::derive($shareLink->id);
        $decrypted = json_decode(AesGcm::decrypt($derivedKey, $cache->ciphertext), true);

        $this->assertCount(1, $decrypted['highlighted']);
        $this->assertSame(['Alice'], $decrypted['highlighted'][0]['highlight_words']);
        $this->assertCount(1, $decrypted['unavailable']);
    }

    public function test_recompute_records_a_calendar_detection(): void
    {
        $this->mockCalendarResponse($this->icsFixture());

        $user = User::factory()->create([
            'calendar_url_ciphertext' => Crypt::encryptString('https://example.com/secret.ics'),
        ]);
        $shareLink = ShareLink::factory()->for($user)->create();

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
        $shareLink = ShareLink::factory()->for($user)->create();

        RecomputeShareLinkAvailability::dispatchSync($shareLink->id);

        $this->assertNull($shareLink->fresh()->cache);
    }

    public function test_a_legacy_share_link_encrypts_with_a_key_derived_from_its_legacy_token_not_its_id(): void
    {
        $this->mockCalendarResponse($this->icsFixture());

        $user = User::factory()->create([
            'calendar_url_ciphertext' => Crypt::encryptString('https://example.com/secret.ics'),
        ]);
        $shareLink = ShareLink::factory()->for($user)->create([
            'legacy_token' => 'legacy-token-for-recompute-test',
        ]);

        RecomputeShareLinkAvailability::dispatchSync($shareLink->id);

        $cache = $shareLink->fresh()->cache;
        $this->assertNotNull($cache);

        $derivedKey = LegacyShareLinkKey::derive('legacy-token-for-recompute-test');
        $decrypted = json_decode(AesGcm::decrypt($derivedKey, $cache->ciphertext), true);

        $this->assertCount(1, $decrypted['unavailable']);
        $this->assertCount(0, $decrypted['highlighted']);
    }

    public function test_recompute_no_ops_when_the_share_link_no_longer_exists(): void
    {
        $this->mockCalendarResponse($this->icsFixture());

        $user = User::factory()->create([
            'calendar_url_ciphertext' => Crypt::encryptString('https://example.com/secret.ics'),
        ]);
        $shareLink = ShareLink::factory()->for($user)->create();
        $shareLinkId = $shareLink->id;
        $shareLink->delete();

        // Would throw (ModelNotFoundException) and land the job in
        // failed_jobs if this still used findOrFail() — the owner deleting
        // a share link while a recompute for it is queued is a real race,
        // not a failure.
        RecomputeShareLinkAvailability::dispatchSync($shareLinkId);

        $this->assertDatabaseCount('share_link_cache', 0);
    }

    /**
     * The race that actually happened in production: the fetch+compute
     * takes long enough for the owner to delete the share link out from
     * under an in-flight job. Without a re-check right before the final
     * write, that insert violates share_link_cache's foreign key instead
     * of just skipping cleanly.
     */
    public function test_recompute_skips_caching_when_the_share_link_is_deleted_mid_flight(): void
    {
        $user = User::factory()->create([
            'calendar_url_ciphertext' => Crypt::encryptString('https://example.com/secret.ics'),
        ]);
        $shareLink = ShareLink::factory()->for($user)->create();
        $icsBody = $this->icsFixture();

        $mock = new MockHandler([
            function () use ($shareLink, $icsBody) {
                $shareLink->delete();

                return new Response(200, [], $icsBody);
            },
        ]);
        $this->app->bind(Client::class, fn () => new Client(['handler' => HandlerStack::create($mock)]));

        RecomputeShareLinkAvailability::dispatchSync($shareLink->id);

        $this->assertDatabaseCount('share_link_cache', 0);
    }
}
