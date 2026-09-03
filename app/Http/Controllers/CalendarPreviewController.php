<?php

namespace App\Http\Controllers;

use App\Services\Calendar\AvailabilityService;
use App\Services\Calendar\CalendarFetcher;
use App\Services\Calendar\EventNormalizer;
use App\Services\Calendar\FeedClassifier;
use App\Services\Calendar\IcsParser;
use App\Support\Regex;
use App\Support\StageTimer;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * §5.2: lets an owner see exactly what a viewer would see BEFORE saving
 * anything. Stricter than the production path (§5.3) — there isn't even
 * consent yet to store anything, so nothing here ever touches the
 * database: not the URL, not the fetched ICS body, not the computed
 * result. It's a plain synchronous request/response to the owner's own
 * browser, over their own authenticated session.
 */
class CalendarPreviewController extends Controller
{
    public function __invoke(
        Request $request,
        CalendarFetcher $fetcher,
        IcsParser $icsParser,
        FeedClassifier $classifier,
        EventNormalizer $normalizer,
        AvailabilityService $availabilityService,
    ): JsonResponse {
        $data = $request->validate([
            'calendar_url' => ['required', 'url'],
            'timezone' => ['nullable', 'string'],
            'calendar_parsing_mode' => ['nullable', 'in:full_detail,free_busy_only'],
            'dnd_event_pattern' => ['nullable', 'string'],
            'nap_event_pattern' => ['nullable', 'string'],
            'work_event_pattern' => ['nullable', 'string'],
            'highlight_clause_pattern' => ['nullable', 'string', Regex::validateSingleCaptureGroup(...)],
            'highlight_split_pattern' => ['nullable', 'string'],
            'activity_clause_pattern' => ['nullable', 'string', Regex::validateSingleCaptureGroup(...)],
            'tentative_pattern' => ['nullable', 'string'],
            'open_end_pattern' => ['nullable', 'string'],
            'open_start_pattern' => ['nullable', 'string'],
            'show_activity' => ['nullable', 'boolean'],
            'highlight_words' => ['nullable', 'array'],
            'highlight_words.*' => ['string'],
            'bypass_dnd' => ['nullable', 'boolean'],
            'availability_settings' => ['nullable', 'array'],
        ]);

        $timezone = $data['timezone'] ?? 'UTC';
        $rangeStart = CarbonImmutable::now($timezone)->startOfDay();
        $rangeEnd = $rangeStart->addDays(14); // Shorter horizon than production — this is a quick sanity check, not the real cache.

        // Diagnostic timing only — trace_id/user_id/counts, never the URL,
        // ICS body, or computed result itself. See StageTimer's doc comment.
        $timer = new StageTimer('availability_preview', [
            'user_id' => $request->user()?->id,
        ]);

        // Nothing from here down may be persisted, logged, or leave this
        // response — see the class doc comment.
        try {
            $icsBody = $fetcher->fetch($data['calendar_url']);
        } catch (\Throwable $e) {
            $timer->fail('fetch');

            throw $e;
        }

        $timer->lap('fetch', ['ics_bytes' => strlen($icsBody)]);

        $parsingMode = $data['calendar_parsing_mode'] ?? 'full_detail';

        $rawItems = $icsParser->parse(
            $icsBody,
            $rangeStart,
            $rangeEnd,
            $data['tentative_pattern'] ?? null,
            $data['open_end_pattern'] ?? null,
            $data['open_start_pattern'] ?? null,
            $parsingMode,
        );
        $detectedMode = $classifier->classify($rawItems);
        $timer->lap('parse_and_classify', ['raw_item_count' => count($rawItems), 'detected_mode' => $detectedMode->value]);

        $events = $normalizer->normalize($rawItems, $parsingMode);
        $timer->lap('normalize', ['event_count' => count($events)]);

        $result = $availabilityService->compute(
            events: $events,
            weeklyAvailability: $data['availability_settings'] ?? [],
            sleepExceptions: [],
            dndEventPattern: $data['dnd_event_pattern'] ?? null,
            napEventPattern: $data['nap_event_pattern'] ?? null,
            highlightWords: $data['highlight_words'] ?? [],
            bypassDnd: $data['bypass_dnd'] ?? false,
            rangeStart: $rangeStart,
            rangeEnd: $rangeEnd,
            highlightClausePattern: $data['highlight_clause_pattern'] ?? null,
            activityClausePattern: $data['activity_clause_pattern'] ?? null,
            showActivity: $data['show_activity'] ?? true,
            workEventPattern: $data['work_event_pattern'] ?? null,
            highlightSplitPattern: $data['highlight_split_pattern'] ?? null,
        );

        $timer->lap('compute_availability', [
            'free_count' => count($result->free),
            'highlighted_count' => count($result->highlighted),
            'unavailable_count' => count($result->unavailable),
            'work_count' => count($result->work),
            'sleep_count' => count($result->sleep),
        ]);

        return response()->json([
            'detected_mode' => $detectedMode->value,
            ...$result->toArray(),
        ]);
    }
}
