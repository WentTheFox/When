<?php

namespace App\Http\Controllers;

use App\Domain\Calendar\AvailabilityResult;
use App\Domain\Calendar\AvailabilitySlot;
use App\Domain\Calendar\ParsedEvent;
use App\Models\SleepException;
use App\Models\User;
use App\Services\Calendar\AvailabilityService;
use App\Services\Calendar\CalendarFetcher;
use App\Services\Calendar\EventNormalizer;
use App\Services\Calendar\IcsParser;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    private const PAST_DAYS = 30;

    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Dashboard/Index', [
            'userName' => $user->name,
            'shareLinkCount' => $user->shareLinks()->where('archived', false)->count(),
            'connectionCount' => $user->connections()->count(),
            'hasCalendarUrl' => $user->calendar_url_ciphertext !== null,
        ]);
    }

    /**
     * Feeds the dashboard's time-breakdown + top-time-spent widgets. One
     * synchronous, on-demand computation per request — nothing cached or
     * persisted beyond this response, same "just compute it" spirit as
     * CalendarPreviewController, except this reads the owner's own stored
     * calendar_url_ciphertext (decrypted transiently, same as
     * RecomputeShareLinkAvailability::handle()) rather than accepting a URL
     * in the request body. A single ICS fetch/parse is reused for both the
     * breakdown rows and every per-share-link highlight total below, rather
     * than fetching the feed once per widget.
     */
    public function statsAvailability(
        Request $request,
        CalendarFetcher $fetcher,
        IcsParser $icsParser,
        EventNormalizer $normalizer,
        AvailabilityService $availabilityService,
    ): JsonResponse {
        $user = $request->user();

        if ($user->calendar_url_ciphertext === null) {
            return response()->json(['error' => 'no_calendar']);
        }

        try {
            $tz = $user->timezone ?? 'UTC';
            $now = CarbonImmutable::now($tz);

            $todayStart = $now->startOfDay();
            $todayEnd = $todayStart->addDay();
            $weekStart = $now->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
            $weekEnd = $now->endOfWeek(CarbonImmutable::SUNDAY);
            $past30Start = $now->subDays(self::PAST_DAYS - 1)->startOfDay();

            $rangeStart = $past30Start;
            $rangeEnd = $weekEnd;

            $calendarUrl = Crypt::decryptString($user->calendar_url_ciphertext);
            $icsBody = $fetcher->fetch($calendarUrl);

            $rawItems = $icsParser->parse(
                $icsBody,
                $rangeStart,
                $rangeEnd,
                $user->tentative_pattern,
                $user->open_end_pattern,
                $user->open_start_pattern,
                $user->calendar_parsing_mode,
            );
            $events = $normalizer->normalize($rawItems, $user->calendar_parsing_mode);

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
                dndEventPattern: $user->dnd_event_pattern,
                napEventPattern: $user->nap_event_pattern,
                highlightWords: [],
                bypassDnd: false,
                rangeStart: $rangeStart,
                rangeEnd: $rangeEnd,
            );

            $rows = [
                $this->buildRow('Today', $result, $events, $user->work_event_pattern, $user->school_event_pattern, $todayStart, $todayEnd, 1),
                $this->buildRow('This week', $result, $events, $user->work_event_pattern, $user->school_event_pattern, $weekStart, $weekStart->addDays(7), 7),
                $this->buildRow('Past '.self::PAST_DAYS.' days', $result, $events, $user->work_event_pattern, $user->school_event_pattern, $past30Start, $todayEnd, self::PAST_DAYS),
            ];

            [$topHighlights, $restHighlights, $noTimeHighlights] = $this->computeHighlightLeaderboard(
                $user, $events, $availabilityService, $weeklyAvailability, $sleepExceptions, $past30Start, $todayEnd,
            );

            return response()->json([
                'rows' => $rows,
                'highlights' => $topHighlights,
                'highlightsRest' => $restHighlights,
                'highlightsNoTime' => $noTimeHighlights,
            ]);
        } catch (\Throwable) {
            return response()->json(['error' => 'fetch_failed']);
        }
    }

    /**
     * @param  ParsedEvent[]  $events
     */
    private function buildRow(
        string $title,
        AvailabilityResult $result,
        array $events,
        ?string $workEventPattern,
        ?string $schoolEventPattern,
        CarbonImmutable $bucketStart,
        CarbonImmutable $bucketEnd,
        int $days,
    ): array {
        $totalMin = $days * 1440;
        $sleepMin = $this->sumSlotMinutes($result->sleep, $bucketStart, $bucketEnd);
        $freeMin = $this->sumSlotMinutes($result->free, $bucketStart, $bucketEnd);
        $workMin = $this->sumEventMinutes($events, $workEventPattern, $bucketStart, $bucketEnd);
        $schoolMin = $this->sumEventMinutes($events, $schoolEventPattern, $bucketStart, $bucketEnd);
        $windowMin = max(0, $totalMin - $sleepMin);

        if ($windowMin === 0) {
            return [
                'title' => $title,
                'notAvail' => true,
                'sleepLabel' => '24:00', 'sleepPct' => 100, 'sleepBarPct' => 100,
                'workLabel' => null, 'workPct' => 0, 'workBarPct' => 0,
                'schoolLabel' => null, 'schoolPct' => 0, 'schoolBarPct' => 0,
                'busyLabel' => null, 'busyPct' => 0, 'busyBarPct' => 0,
                'freeLabel' => null, 'freePct' => null,
            ];
        }

        $busyMin = max(0, $windowMin - $freeMin - $workMin - $schoolMin);
        $hhmm = fn (int $m) => sprintf('%d:%02d', intdiv($m, 60), $m % 60);

        return [
            'title' => $title,
            'notAvail' => false,
            'sleepLabel' => $hhmm($sleepMin),
            'sleepPct' => (int) round($sleepMin / $totalMin * 100),
            'sleepBarPct' => (int) round($sleepMin / $totalMin * 100),
            'workLabel' => $hhmm($workMin),
            'workPct' => min(100, (int) round($workMin / $windowMin * 100)),
            'workBarPct' => (int) round($workMin / $totalMin * 100),
            'schoolLabel' => $hhmm($schoolMin),
            'schoolPct' => min(100, (int) round($schoolMin / $windowMin * 100)),
            'schoolBarPct' => (int) round($schoolMin / $totalMin * 100),
            'busyLabel' => $hhmm($busyMin),
            'busyPct' => min(100, (int) round($busyMin / $windowMin * 100)),
            'busyBarPct' => (int) round($busyMin / $totalMin * 100),
            'freeLabel' => $hhmm($freeMin),
            'freePct' => min(100, (int) round($freeMin / $windowMin * 100)),
        ];
    }

    /**
     * "Top time spent": one entry per share link that has highlight words,
     * summing App\Domain\Calendar\AvailabilitySlot minutes matched by that
     * link's own words over the trailing 30 days — the same matching
     * RecomputeShareLinkAvailability uses to build a viewer's own
     * highlighted blocks, just aggregated for the owner instead of encrypted
     * and cached for a viewer. No new "highlight group" concept: a share
     * link already optionally has a 1:1 linked Connection
     * (Connection::share_link_id), whose name becomes this row's label
     * (falling back to the link's own label) — resolved client-side only,
     * since both are §0.1 E2EE and this method never sees their plaintext.
     *
     * @param  ParsedEvent[]  $events
     * @param  array<int, array{wake: ?string, sleep: ?string}>  $weeklyAvailability
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}[]  $sleepExceptions
     * @return array{0: array, 1: array, 2: array} [top 10 (minutes > 0), rest (minutes > 0), no-time-yet (minutes === 0)]
     */
    private function computeHighlightLeaderboard(
        User $user,
        array $events,
        AvailabilityService $availabilityService,
        array $weeklyAvailability,
        array $sleepExceptions,
        CarbonImmutable $rangeStart,
        CarbonImmutable $rangeEnd,
    ): array {
        $withTime = [];
        $noTime = [];

        $shareLinks = $user->shareLinks()->where('archived', false)->with('connection')->get();
        $activityRoles = $user->activityRoles->map(fn ($r) => ['pattern' => $r->pattern, 'label' => $r->label])->all();

        foreach ($shareLinks as $shareLink) {
            $words = $shareLink->words()
                ->pluck('word_ciphertext')
                ->map(fn (string $ciphertext) => Crypt::decryptString($ciphertext))
                ->all();

            if (empty($words)) {
                continue;
            }

            $linkResult = $availabilityService->compute(
                events: $events,
                weeklyAvailability: $weeklyAvailability,
                sleepExceptions: $sleepExceptions,
                dndEventPattern: $user->dnd_event_pattern,
                napEventPattern: $user->nap_event_pattern,
                highlightWords: $words,
                bypassDnd: $shareLink->bypass_dnd,
                rangeStart: $rangeStart,
                rangeEnd: $rangeEnd,
                highlightClausePattern: $user->highlight_clause_pattern,
                highlightSplitPattern: $user->highlight_split_pattern,
                activityRoles: $activityRoles,
            );

            $minutes = $this->sumSlotMinutes($linkResult->highlighted, $rangeStart, $rangeEnd);

            $connection = $shareLink->connection;

            $entry = [
                'share_link_id' => $shareLink->id,
                'minutes' => $minutes,
                'connection' => $connection ? ['id' => $connection->id, 'name_ciphertext' => $connection->name_ciphertext] : null,
                'share_link_label_ciphertext' => $shareLink->label_ciphertext,
                'events' => $this->matchedSlotsInRange($linkResult->highlighted, $rangeStart, $rangeEnd),
            ];

            if ($minutes > 0) {
                $withTime[] = $entry;
            } else {
                $noTime[] = $entry;
            }
        }

        usort($withTime, fn ($a, $b) => $b['minutes'] <=> $a['minutes']);

        return [array_slice($withTime, 0, 10), array_slice($withTime, 10), $noTime];
    }

    /**
     * Feeds the "highlight events" dialog: every highlighted slot for one
     * share link within the stats range, sorted chronologically. Unlike the
     * source app's own dialog — a plain substring match against raw ICS
     * event titles — this app's highlight matching is clause-based (§ see
     * HighlightMatcher's own doc comment: "with X"/one of the owner's own
     * configured activity_roles), so there's no single raw "event name" to
     * show per match. AvailabilitySlot already carries everything the
     * /free viewer itself shows for a highlighted block (activity,
     * activity_label, tentative edges, matched words) — reusing that same
     * shape here instead of re-deriving a name keeps this dialog honest
     * about what actually matched.
     *
     * @param  AvailabilitySlot[]  $slots
     * @return array<int, array>
     */
    private function matchedSlotsInRange(array $slots, CarbonImmutable $rangeStart, CarbonImmutable $rangeEnd): array
    {
        $inRange = array_values(array_filter(
            $slots,
            fn (AvailabilitySlot $slot) => $slot->end->gt($rangeStart) && $slot->start->lt($rangeEnd),
        ));

        usort($inRange, fn (AvailabilitySlot $a, AvailabilitySlot $b) => $a->start <=> $b->start);

        return array_map(fn (AvailabilitySlot $slot) => $slot->toArray(), $inRange);
    }

    /** @param  AvailabilitySlot[]  $slots */
    private function sumSlotMinutes(array $slots, CarbonImmutable $bucketStart, CarbonImmutable $bucketEnd): int
    {
        $minutes = 0;

        foreach ($slots as $slot) {
            $start = $slot->start->max($bucketStart);
            $end = $slot->end->min($bucketEnd);

            if ($end->gt($start)) {
                $minutes += $start->diffInMinutes($end);
            }
        }

        return $minutes;
    }

    /** @param  ParsedEvent[]  $events */
    private function sumEventMinutes(array $events, ?string $pattern, CarbonImmutable $bucketStart, CarbonImmutable $bucketEnd): int
    {
        if ($pattern === null || $pattern === '') {
            return 0;
        }

        $minutes = 0;

        foreach ($events as $event) {
            if (! $event->matchesEventNamePattern($pattern)) {
                continue;
            }

            $start = $event->start->max($bucketStart);
            $end = $event->end->min($bucketEnd);

            if ($end->gt($start)) {
                $minutes += $start->diffInMinutes($end);
            }
        }

        return $minutes;
    }
}
