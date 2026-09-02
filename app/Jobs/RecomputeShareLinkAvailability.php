<?php

namespace App\Jobs;

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
use App\Support\StageTimer;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
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
class RecomputeShareLinkAvailability implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** How far forward to compute — there's no reason to hold years of calendar history in memory. */
    private const LOOKAHEAD_DAYS = 60;

    /**
     * Safety cap on the uniqueness lock, in case a worker dies mid-job
     * without releasing it (the lock otherwise releases as soon as this
     * job finishes handling, success or failure). Prevents a single
     * crashed run from permanently blocking recomputes for this link.
     */
    public int $uniqueFor = 600;

    public function __construct(private readonly string $shareLinkId) {}

    /**
     * One outstanding recompute per share link at a time — the polling
     * frontend re-dispatches on every stale request, and while the queue
     * worker is down that can pile up dozens of redundant jobs for the
     * same link. ShouldBeUnique collapses those to one queued/processing
     * job; duplicates dispatched in between are silently dropped.
     */
    public function uniqueId(): string
    {
        return $this->shareLinkId;
    }

    public function handle(
        CalendarFetcher $fetcher,
        IcsParser $icsParser,
        FeedClassifier $classifier,
        EventNormalizer $normalizer,
        AvailabilityService $availabilityService,
    ): void {
        // Not findOrFail(): the owner deleting the share link while this job
        // is still queued or in flight (§the new delete feature) is a real,
        // expected race, not a failure — nothing left to compute for, so
        // just no-op rather than landing in failed_jobs.
        $shareLink = ShareLink::with('user')->find($this->shareLinkId);

        if ($shareLink === null) {
            return;
        }

        $user = $shareLink->user;

        if ($user->calendar_url_ciphertext === null) {
            return;
        }

        $rangeStart = CarbonImmutable::now($user->timezone ?? 'UTC')->startOfDay();
        $rangeEnd = $rangeStart->addDays(self::LOOKAHEAD_DAYS);

        // Diagnostic timing only — see StageTimer's own doc comment for why
        // trace_id is a fresh UUID (never an owner/share-link id) and why
        // context here is restricted to ids/counts/mode labels, never any
        // of the plaintext this method handles.
        $timer = new StageTimer('availability_recompute', [
            'share_link_id' => $shareLink->id,
            'user_id' => $user->id,
        ]);

        // Everything from here to the encrypt-and-discard block deals in
        // plaintext (calendar_url, the raw ICS body, event titles/locations,
        // highlight words, the share link's raw content key). None of it may
        // be logged, persisted, or leave this method.
        $calendarUrl = Crypt::decryptString($user->calendar_url_ciphertext);

        try {
            $icsBody = $fetcher->fetch($calendarUrl);
        } catch (\Throwable $e) {
            $timer->fail('fetch');

            throw $e;
        }

        $timer->lap('fetch', ['ics_bytes' => strlen($icsBody)]);

        $rawItems = $icsParser->parse(
            $icsBody,
            $rangeStart,
            $rangeEnd,
            $user->tentative_pattern,
            $user->open_end_pattern,
            $user->open_start_pattern,
            $user->calendar_parsing_mode,
        );
        $timer->lap('parse', ['raw_item_count' => count($rawItems)]);

        $detectedMode = $classifier->classify($rawItems);
        $timer->lap('classify', ['detected_mode' => $detectedMode->value]);

        CalendarDetection::create([
            'user_id' => $user->id,
            'detected_mode' => $detectedMode->value,
            'fetched_at' => now(),
        ]);

        $events = $normalizer->normalize($rawItems, $user->calendar_parsing_mode);
        $timer->lap('normalize', ['event_count' => count($events)]);

        $highlightWords = $shareLink->words()
            ->pluck('word_ciphertext')
            ->map(fn (string $ciphertext) => Crypt::decryptString($ciphertext))
            ->all();

        $sleepExceptions = SleepException::where('user_id', $user->id)
            ->get(['start_date', 'end_date'])
            ->map(fn ($exception) => [
                'start' => CarbonImmutable::parse($exception->start_date),
                'end' => CarbonImmutable::parse($exception->end_date),
            ])
            ->all();

        $weeklyAvailability = $user->availability_settings ?? [];

        $result = $availabilityService->compute(
            events: $events,
            weeklyAvailability: $weeklyAvailability,
            sleepExceptions: $sleepExceptions,
            dndEventName: $user->dnd_event_name,
            napEventName: $user->nap_event_name,
            highlightWords: $highlightWords,
            bypassDnd: $shareLink->bypass_dnd,
            rangeStart: $rangeStart,
            rangeEnd: $rangeEnd,
            highlightClausePattern: $user->highlight_clause_pattern,
            activityClausePattern: $user->activity_clause_pattern,
            showActivity: $shareLink->show_activity,
            workEventName: $user->work_event_name,
            highlightSplitPattern: $user->highlight_split_pattern,
        );

        $timer->lap('compute_availability', [
            'free_count' => count($result->free),
            'highlighted_count' => count($result->highlighted),
            'unavailable_count' => count($result->unavailable),
            'work_count' => count($result->work),
            'sleep_count' => count($result->sleep),
        ]);

        $resultJson = json_encode($result->toArray());
        $contentKey = LegacyShareLinkKey::derive($shareLink->legacy_token ?? $shareLink->id);

        $ciphertext = AesGcm::encrypt($contentKey, $resultJson);

        // Re-check rather than letting this hit share_link_cache's foreign
        // key: the fetch above can take long enough for the owner to have
        // deleted the link in the meantime, and there's nothing left to
        // cache a result for at that point.
        if (ShareLink::whereKey($shareLink->id)->exists()) {
            ShareLinkCache::updateOrCreate(
                ['share_link_id' => $shareLink->id],
                [
                    'ciphertext' => $ciphertext,
                    'computed_range_start' => $rangeStart,
                    'computed_range_end' => $rangeEnd,
                    'encrypted_at' => now(),
                ],
            );
        }

        $timer->lap('encrypt_and_store');

        unset($calendarUrl, $icsBody, $resultJson, $contentKey, $highlightWords);
    }
}
