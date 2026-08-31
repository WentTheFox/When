<?php

namespace App\Http\Controllers;

use App\Domain\Calendar\ManualTag;
use App\Services\Calendar\AvailabilityService;
use App\Services\Calendar\CalendarFetcher;
use App\Services\Calendar\EventNormalizer;
use App\Services\Calendar\FeedClassifier;
use App\Services\Calendar\IcsParser;
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
            'calendar_parsing_mode' => ['nullable', 'in:full_detail,free_busy_only,auto'],
            'dnd_event_name' => ['nullable', 'string'],
            'nap_event_name' => ['nullable', 'string'],
            'highlight_clause_pattern' => ['nullable', 'string'],
            'highlight_words' => ['nullable', 'array'],
            'highlight_words.*' => ['string'],
            'bypass_dnd' => ['nullable', 'boolean'],
            'availability_settings' => ['nullable', 'array'],
            'manual_tags' => ['nullable', 'array'],
            'manual_tags.*.word' => ['required_with:manual_tags', 'string'],
            'manual_tags.*.weekday' => ['nullable', 'integer', 'min:0', 'max:6'],
            'manual_tags.*.start_time' => ['required_with:manual_tags', 'string'],
            'manual_tags.*.end_time' => ['required_with:manual_tags', 'string'],
        ]);

        $timezone = $data['timezone'] ?? 'UTC';
        $rangeStart = CarbonImmutable::now($timezone)->startOfDay();
        $rangeEnd = $rangeStart->addDays(14); // Shorter horizon than production — this is a quick sanity check, not the real cache.

        // Nothing from here down may be persisted, logged, or leave this
        // response — see the class doc comment.
        $icsBody = $fetcher->fetch($data['calendar_url']);

        $rawItems = $icsParser->parse($icsBody, $rangeStart, $rangeEnd);
        $detectedMode = $classifier->classify($rawItems);

        $parsingMode = $data['calendar_parsing_mode'] ?? 'auto';
        $events = $normalizer->normalize($rawItems, $parsingMode);

        $manualTags = array_map(
            fn (array $tag) => new ManualTag(
                word: $tag['word'],
                weekday: $tag['weekday'] ?? null,
                startTime: $tag['start_time'],
                endTime: $tag['end_time'],
            ),
            $data['manual_tags'] ?? [],
        );

        $slots = $availabilityService->compute(
            events: $events,
            weeklyAvailability: $data['availability_settings'] ?? [],
            sleepExceptions: [],
            dndEventName: $data['dnd_event_name'] ?? null,
            napEventName: $data['nap_event_name'] ?? null,
            highlightWords: $data['highlight_words'] ?? [],
            manualTags: $manualTags,
            bypassDnd: $data['bypass_dnd'] ?? false,
            rangeStart: $rangeStart,
            rangeEnd: $rangeEnd,
            highlightClausePattern: $data['highlight_clause_pattern'] ?? null,
        );

        return response()->json([
            'detected_mode' => $detectedMode->value,
            'slots' => array_map(fn ($slot) => $slot->toArray(), $slots),
        ]);
    }
}
