<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Calendar\ActivityExtractor;
use App\Services\Calendar\HighlightMatcher;
use App\Services\Calendar\IcsParser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
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
     * They're just the suggested starting value the settings form
     * pre-fills for a user who's never set one, named here so that's a
     * single source of truth instead of a literal baked into the Vue page.
     */
    private const SUGGESTED_DND_EVENT_NAME = 'DND';

    private const SUGGESTED_NAP_EVENT_NAME = 'Nap';

    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Dashboard/Settings', [
            'settings' => [
                'timezone' => $user->timezone,
                'week_start' => $user->week_start,
                'dnd_event_name' => $user->dnd_event_name,
                'nap_event_name' => $user->nap_event_name,
                'calendar_parsing_mode' => $user->calendar_parsing_mode,
                'highlight_clause_pattern' => $user->highlight_clause_pattern,
                'activity_clause_pattern' => $user->activity_clause_pattern,
                'tentative_pattern' => $user->tentative_pattern,
                'public_page_title_en' => $user->public_page_title_en,
                'public_page_title_hu' => $user->public_page_title_hu,
                'name' => $user->name,
                'accent_color' => $user->accent_color,
                'secondary_color' => $user->secondary_color,
                'sleep_color' => $user->sleep_color,
                'busy_color' => $user->busy_color,
                'free_color' => $user->free_color,
                'highlight_color' => $user->highlight_color,
                'now_color' => $user->now_color,
                'availability' => $user->availability_settings ?? [],
            ],
            'defaults' => [
                'dndEventName' => self::SUGGESTED_DND_EVENT_NAME,
                'napEventName' => self::SUGGESTED_NAP_EVENT_NAME,
                'highlightClausePattern' => HighlightMatcher::DEFAULT_CLAUSE_PATTERN,
                'activityClausePattern' => ActivityExtractor::DEFAULT_PATTERN,
                'tentativePattern' => IcsParser::DEFAULT_TENTATIVE_TITLE_PATTERN,
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
            'dnd_event_name' => ['nullable', 'string', 'max:255'],
            'nap_event_name' => ['nullable', 'string', 'max:255'],
            'calendar_parsing_mode' => ['required', 'in:full_detail,free_busy_only,auto'],
            'highlight_clause_pattern' => ['nullable', 'string'],
            'activity_clause_pattern' => ['nullable', 'string'],
            'tentative_pattern' => ['nullable', 'string'],
            'public_page_title_en' => ['nullable', 'string', 'max:255'],
            'public_page_title_hu' => ['nullable', 'string', 'max:255'],
            'accent_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'secondary_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'sleep_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'busy_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'free_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'highlight_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'now_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
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
            'dnd_event_name' => $data['dnd_event_name'] ?? null,
            'nap_event_name' => $data['nap_event_name'] ?? null,
            'calendar_parsing_mode' => $data['calendar_parsing_mode'],
            'highlight_clause_pattern' => $data['highlight_clause_pattern'] ?? null,
            'activity_clause_pattern' => $data['activity_clause_pattern'] ?? null,
            'tentative_pattern' => $data['tentative_pattern'] ?? null,
            'public_page_title_en' => $data['public_page_title_en'] ?? null,
            'public_page_title_hu' => $data['public_page_title_hu'] ?? null,
            'accent_color' => $data['accent_color'] ?? null,
            'secondary_color' => $data['secondary_color'] ?? null,
            'sleep_color' => $data['sleep_color'] ?? null,
            'busy_color' => $data['busy_color'] ?? null,
            'free_color' => $data['free_color'] ?? null,
            'highlight_color' => $data['highlight_color'] ?? null,
            'now_color' => $data['now_color'] ?? null,
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
