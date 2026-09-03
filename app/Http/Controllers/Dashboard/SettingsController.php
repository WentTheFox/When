<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Calendar\ActivityExtractor;
use App\Services\Calendar\HighlightMatcher;
use App\Services\Calendar\IcsParser;
use App\Support\CalendarParsingMode;
use App\Support\ColorSwatchKey;
use App\Support\IconKey;
use App\Support\NowColorPresetKey;
use App\Support\Regex;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Owner settings (Stage 7). Almost none of this needs the client vault —
 * per the users table migration, only calendar_url is even ciphertext, and
 * that's the §0.2 server-runtime tier (Crypt), not the §0.1/§0.3 client
 * vault. Everything else here (timezone, event-name patterns, wake/sleep
 * windows, parsing mode, colors, page title) is plain metadata, not
 * "content" in §0.1's E2EE-guarantee sense.
 */
class SettingsController extends Controller
{
    /**
     * Unlike HighlightMatcher::DEFAULT_CLAUSE_PATTERN, these aren't a
     * functional fallback — a blank dnd/nap pattern genuinely matches
     * nothing (App\Domain\Calendar\ParsedEvent::matchesEventNamePattern).
     * They're just the suggested starting value shown in the settings
     * form's field description for a user who's never set one (never
     * pre-filled into the field itself — an untouched, still-blank-in-the-
     * database setting must never look already active), named here so
     * that's a single source of truth instead of a literal baked into the
     * Vue page. Lowercase and `^...$`-anchored (a *whole-title* match, not
     * the "contains anywhere" default every one of these fields has) so
     * the suggestion itself doubles as a worked example of anchoring —
     * see the Vue page's own "What these text-match fields actually do"
     * crash course.
     */
    private const SUGGESTED_DND_EVENT_PATTERN = '^dnd$';

    private const SUGGESTED_NAP_EVENT_PATTERN = '^nap$';

    /**
     * Same non-functional-fallback caveat as the dnd/nap suggestions above
     * — a blank pattern matches nothing. Feeds the dashboard time-breakdown
     * widget's "work" bucket (DashboardController::statsAvailability) and
     * the /free calendar's own work category.
     */
    private const SUGGESTED_WORK_EVENT_PATTERN = '^work$';

    /**
     * Same non-functional-fallback caveat as dnd/nap/work above. Not
     * anchored to the whole title like those three, deliberately —
     * "school" or "class" showing up anywhere in a title (a room number
     * or teacher's name tacked on, say) should still count, the same
     * "contains anywhere" default every other event-name/pattern field on
     * this page already has.
     */
    private const SUGGESTED_SCHOOL_EVENT_PATTERN = '(school|class)';

    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Dashboard/Settings', [
            'settings' => [
                'timezone' => $user->timezone,
                'week_start' => $user->week_start,
                'dnd_event_pattern' => $user->dnd_event_pattern,
                'nap_event_pattern' => $user->nap_event_pattern,
                'work_event_pattern' => $user->work_event_pattern,
                'school_event_pattern' => $user->school_event_pattern,
                'calendar_parsing_mode' => $user->calendar_parsing_mode,
                'highlight_clause_pattern' => $user->highlight_clause_pattern,
                'highlight_split_pattern' => $user->highlight_split_pattern,
                'activity_clause_pattern' => $user->activity_clause_pattern,
                'tentative_pattern' => $user->tentative_pattern,
                'open_end_pattern' => $user->open_end_pattern,
                'open_start_pattern' => $user->open_start_pattern,
                'public_page_title_en' => $user->public_page_title_en,
                'public_page_title_hu' => $user->public_page_title_hu,
                'name' => $user->name,
                'accent_color_key' => $user->accent_color_key,
                'secondary_color_key' => $user->secondary_color_key,
                'sleep_color_key' => $user->sleep_color_key,
                'busy_color_key' => $user->busy_color_key,
                'work_color_key' => $user->work_color_key,
                'school_color_key' => $user->school_color_key,
                'free_color_key' => $user->free_color_key,
                'highlight_color_key' => $user->highlight_color_key,
                'free_icon_key' => $user->free_icon_key,
                'busy_icon_key' => $user->busy_icon_key,
                'work_icon_key' => $user->work_icon_key,
                'school_icon_key' => $user->school_icon_key,
                'sleep_icon_key' => $user->sleep_icon_key,
                'highlight_icon_key' => $user->highlight_icon_key,
                'now_color_key' => $user->now_color_key,
                'availability' => $user->availability_settings ?? [],
            ],
            'defaults' => [
                'dndEventPattern' => self::SUGGESTED_DND_EVENT_PATTERN,
                'napEventPattern' => self::SUGGESTED_NAP_EVENT_PATTERN,
                'workEventPattern' => self::SUGGESTED_WORK_EVENT_PATTERN,
                'schoolEventPattern' => self::SUGGESTED_SCHOOL_EVENT_PATTERN,
                'highlightClausePattern' => HighlightMatcher::DEFAULT_CLAUSE_PATTERN,
                'highlightSplitPattern' => HighlightMatcher::DEFAULT_SPLIT_PATTERN,
                'activityClausePattern' => ActivityExtractor::DEFAULT_PATTERN,
                'tentativePattern' => IcsParser::DEFAULT_TENTATIVE_TITLE_PATTERN,
                'openEndPattern' => IcsParser::DEFAULT_OPEN_END_TITLE_PATTERN,
                'openStartPattern' => IcsParser::DEFAULT_OPEN_START_TITLE_PATTERN,
            ],
            'timezones' => \DateTimeZone::listIdentifiers(),
            // Shown back to the owner verbatim, not masked — this is §0.2
            // server-runtime tier (Crypt/APP_KEY), not §0.1 client-vault
            // E2EE, so the server already has to be able to decrypt it on
            // every recompute regardless. Hiding it from the owner's own
            // settings page added confusion (an empty-looking input despite
            // a "Configured" badge) without any actual confidentiality
            // benefit — the server can decrypt this either way.
            'calendarUrl' => $user->calendar_url_ciphertext !== null
                ? Crypt::decryptString($user->calendar_url_ciphertext)
                : null,
            'sleepExceptions' => $user->sleepExceptions()->orderBy('start_date')->get()->map(fn ($e) => [
                'id' => $e->id,
                'start_date' => $e->start_date->toDateString(),
                'end_date' => $e->end_date->toDateString(),
                'label_ciphertext' => $e->label_ciphertext,
            ]),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'timezone' => ['required', 'timezone'],
            'week_start' => ['required', 'integer', 'between:0,6'],
            'dnd_event_pattern' => ['nullable', 'string', 'max:255', Regex::validateCompiles(...)],
            'nap_event_pattern' => ['nullable', 'string', 'max:255', Regex::validateCompiles(...)],
            'work_event_pattern' => ['nullable', 'string', 'max:255', Regex::validateCompiles(...)],
            'school_event_pattern' => ['nullable', 'string', 'max:255', Regex::validateCompiles(...)],
            'calendar_parsing_mode' => ['required', Rule::enum(CalendarParsingMode::class)],
            'highlight_clause_pattern' => ['nullable', 'string', 'max:500', Regex::validateSingleCaptureGroup(...)],
            'highlight_split_pattern' => ['nullable', 'string', 'max:255', Regex::validateCompiles(...)],
            'activity_clause_pattern' => ['nullable', 'string', 'max:500', Regex::validateSingleCaptureGroup(...)],
            'tentative_pattern' => ['nullable', 'string', 'max:500', Regex::validateCompiles(...)],
            'open_end_pattern' => ['nullable', 'string', 'max:500', Regex::validateCompiles(...)],
            'open_start_pattern' => ['nullable', 'string', 'max:500', Regex::validateCompiles(...)],
            'public_page_title_en' => ['nullable', 'string', 'max:255'],
            'public_page_title_hu' => ['nullable', 'string', 'max:255'],
            'accent_color_key' => ['nullable', Rule::enum(ColorSwatchKey::class)],
            'secondary_color_key' => ['nullable', Rule::enum(ColorSwatchKey::class)],
            'sleep_color_key' => ['nullable', Rule::enum(ColorSwatchKey::class)],
            'busy_color_key' => ['nullable', Rule::enum(ColorSwatchKey::class)],
            'work_color_key' => ['nullable', Rule::enum(ColorSwatchKey::class)],
            'school_color_key' => ['nullable', Rule::enum(ColorSwatchKey::class)],
            'free_color_key' => ['nullable', Rule::enum(ColorSwatchKey::class)],
            'highlight_color_key' => ['nullable', Rule::enum(ColorSwatchKey::class)],
            'free_icon_key' => ['nullable', Rule::enum(IconKey::class)],
            'busy_icon_key' => ['nullable', Rule::enum(IconKey::class)],
            'work_icon_key' => ['nullable', Rule::enum(IconKey::class)],
            'school_icon_key' => ['nullable', Rule::enum(IconKey::class)],
            'sleep_icon_key' => ['nullable', Rule::enum(IconKey::class)],
            'highlight_icon_key' => ['nullable', Rule::enum(IconKey::class)],
            'now_color_key' => ['nullable', Rule::enum(NowColorPresetKey::class)],
            'availability' => ['nullable', 'array'],
            'availability.*.wake' => ['nullable', 'string'],
            'availability.*.sleep' => ['nullable', 'string'],
        ]);

        $user = $request->user();

        $availability = [];
        foreach ($data['availability'] ?? [] as $weekday => $config) {
            $availability[(int) $weekday] = [
                'wake' => $config['wake'] ?? null,
                'sleep' => $config['sleep'] ?? null,
            ];
        }

        $user->fill([
            'timezone' => $data['timezone'],
            'week_start' => $data['week_start'],
            'dnd_event_pattern' => $data['dnd_event_pattern'] ?? null,
            'nap_event_pattern' => $data['nap_event_pattern'] ?? null,
            'work_event_pattern' => $data['work_event_pattern'] ?? null,
            'school_event_pattern' => $data['school_event_pattern'] ?? null,
            'calendar_parsing_mode' => $data['calendar_parsing_mode'],
            'highlight_clause_pattern' => $data['highlight_clause_pattern'] ?? null,
            'highlight_split_pattern' => $data['highlight_split_pattern'] ?? null,
            'activity_clause_pattern' => $data['activity_clause_pattern'] ?? null,
            'tentative_pattern' => $data['tentative_pattern'] ?? null,
            'open_end_pattern' => $data['open_end_pattern'] ?? null,
            'open_start_pattern' => $data['open_start_pattern'] ?? null,
            'public_page_title_en' => $data['public_page_title_en'] ?? null,
            'public_page_title_hu' => $data['public_page_title_hu'] ?? null,
            'accent_color_key' => $data['accent_color_key'] ?? null,
            'secondary_color_key' => $data['secondary_color_key'] ?? null,
            'sleep_color_key' => $data['sleep_color_key'] ?? null,
            'busy_color_key' => $data['busy_color_key'] ?? null,
            'work_color_key' => $data['work_color_key'] ?? null,
            'school_color_key' => $data['school_color_key'] ?? null,
            'free_color_key' => $data['free_color_key'] ?? null,
            'highlight_color_key' => $data['highlight_color_key'] ?? null,
            'free_icon_key' => $data['free_icon_key'] ?? null,
            'busy_icon_key' => $data['busy_icon_key'] ?? null,
            'work_icon_key' => $data['work_icon_key'] ?? null,
            'school_icon_key' => $data['school_icon_key'] ?? null,
            'sleep_icon_key' => $data['sleep_icon_key'] ?? null,
            'highlight_icon_key' => $data['highlight_icon_key'] ?? null,
            'now_color_key' => $data['now_color_key'] ?? null,
            'availability_settings' => $availability,
        ])->save();

        return back()->with('status', 'Settings saved.');
    }

    /**
     * Deliberately its own action, not folded into update() above: a
     * pending, not-yet-previewed calendar_url used to make the *entire*
     * settings save fail closed together (e.g. changing your timezone
     * while an old unconfirmed URL edit was still sitting in that field
     * silently saved nothing at all, calendar_url included) — genuinely
     * confusing since the validation error's connection to "why didn't my
     * timezone change save" isn't obvious. Splitting it out means an
     * unconfirmed URL can only ever block itself.
     */
    public function updateCalendarUrl(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'calendar_url' => ['required', 'url'],
            'calendar_url_preview_confirmed' => ['required', 'accepted'],
        ]);

        // Direct property assignment, not update()/fill() — calendar_url_ciphertext
        // is deliberately not in User::$fillable (nothing should mass-assign
        // ciphertext), so mass assignment here would silently no-op.
        $user = $request->user();
        $user->calendar_url_ciphertext = Crypt::encryptString($data['calendar_url']);
        $user->save();

        return back()->with('status', 'Calendar URL saved.');
    }
}
