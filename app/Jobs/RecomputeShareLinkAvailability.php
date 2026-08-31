<?php

namespace App\Jobs;

use App\Domain\Calendar\ManualTag;
use App\Models\CalendarDetection;
use App\Models\ShareLink;
use App\Models\ShareLinkCache;
use App\Models\SleepException;
use App\Services\Calendar\AvailabilityService;
use App\Services\Calendar\CalendarFetcher;
use App\Services\Calendar\EventNormalizer;
use App\Services\Calendar\FeedClassifier;
use App\Services\Calendar\IcsParser;
use App\Services\Crypto\AesGcm;
use App\Services\Crypto\LegacyShareLinkKey;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Crypt;

/**
 * §5.3's encryption boundary, end to end: decrypts calendar_url and the
 * share link's plaintext-needing fields transiently, computes the result,
 * encrypts it immediately with the share link's content key, and discards
 * every plaintext local variable. Nothing decrypted in here is ever logged,
 * persisted outside this method's stack, or included in an exception —
 * see PlaintextNeverLoggedTest for the regression test this discipline
 * exists to satisfy.
 *
 * IMPORTANT: this job's only serialized property is $shareLinkId. Never add
 * a property that holds decrypted material — job payloads land in the
 * `jobs`/`failed_jobs` tables verbatim.
 */
class RecomputeShareLinkAvailability implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** How far forward to compute — there's no reason to hold years of calendar history in memory. */
    private const LOOKAHEAD_DAYS = 60;

    public function __construct(private readonly string $shareLinkId) {}

    public function handle(
        CalendarFetcher $fetcher,
        IcsParser $icsParser,
        FeedClassifier $classifier,
        EventNormalizer $normalizer,
        AvailabilityService $availabilityService,
    ): void {
        $shareLink = ShareLink::with('user')->findOrFail($this->shareLinkId);
        $user = $shareLink->user;

        if ($user->calendar_url_ciphertext === null || ! $this->hasUsableContentKey($shareLink)) {
            return;
        }

        $rangeStart = CarbonImmutable::now($user->timezone ?? 'UTC')->startOfDay();
        $rangeEnd = $rangeStart->addDays(self::LOOKAHEAD_DAYS);

        // Everything from here to the encrypt-and-discard block deals in
        // plaintext (calendar_url, the raw ICS body, event titles/locations,
        // highlight words, the share link's raw content key). None of it may
        // be logged, persisted, or leave this method.
        $calendarUrl = Crypt::decryptString($user->calendar_url_ciphertext);
        $icsBody = $fetcher->fetch($calendarUrl);

        $rawItems = $icsParser->parse($icsBody, $rangeStart, $rangeEnd);
        $detectedMode = $classifier->classify($rawItems);

        CalendarDetection::create([
            'user_id' => $user->id,
            'detected_mode' => $detectedMode->value,
            'fetched_at' => now(),
        ]);

        $events = $normalizer->normalize($rawItems, $user->calendar_parsing_mode);

        $highlightWords = $shareLink->words()
            ->pluck('word_ciphertext')
            ->map(fn (string $ciphertext) => Crypt::decryptString($ciphertext))
            ->all();

        $manualTags = $shareLink->manualTags->map(fn ($tag) => new ManualTag(
            word: Crypt::decryptString($tag->word_ciphertext),
            weekday: $tag->weekday,
            startTime: $tag->start_time,
            endTime: $tag->end_time,
        ))->all();

        $sleepExceptions = SleepException::where('user_id', $user->id)
            ->get(['start_date', 'end_date'])
            ->map(fn ($exception) => [
                'start' => CarbonImmutable::parse($exception->start_date),
                'end' => CarbonImmutable::parse($exception->end_date),
            ])
            ->all();

        $weeklyAvailability = $user->availability_settings ?? [];

        $slots = $availabilityService->compute(
            events: $events,
            weeklyAvailability: $weeklyAvailability,
            sleepExceptions: $sleepExceptions,
            dndEventName: $user->dnd_event_name,
            napEventName: $user->nap_event_name,
            highlightWords: $highlightWords,
            manualTags: $manualTags,
            bypassDnd: $shareLink->bypass_dnd,
            rangeStart: $rangeStart,
            rangeEnd: $rangeEnd,
            highlightClausePattern: $user->highlight_clause_pattern,
        );

        $resultJson = json_encode(array_map(fn ($slot) => $slot->toArray(), $slots));
        $contentKey = $this->resolveContentKey($shareLink);

        $ciphertext = AesGcm::encrypt($contentKey, $resultJson);

        ShareLinkCache::updateOrCreate(
            ['share_link_id' => $shareLink->id],
            [
                'ciphertext' => $ciphertext,
                'computed_range_start' => $rangeStart,
                'computed_range_end' => $rangeEnd,
                'encrypted_at' => now(),
            ],
        );

        unset($calendarUrl, $icsBody, $resultJson, $contentKey, $highlightWords, $manualTags);
    }

    private function hasUsableContentKey(ShareLink $shareLink): bool
    {
        return $shareLink->content_key_ciphertext !== null || $shareLink->legacy_token !== null;
    }

    /**
     * §0.5: a migrated share link's key is derived deterministically from
     * its legacy token rather than stored — see LegacyShareLinkKey's doc
     * comment. Every other share link keeps its random,
     * content_key_ciphertext-stored key.
     */
    private function resolveContentKey(ShareLink $shareLink): string
    {
        if ($shareLink->legacy_token !== null) {
            return LegacyShareLinkKey::derive($shareLink->legacy_token);
        }

        return base64_decode(Crypt::decryptString($shareLink->content_key_ciphertext), true);
    }
}
