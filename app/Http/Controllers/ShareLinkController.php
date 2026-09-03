<?php

namespace App\Http\Controllers;

use App\Models\Invite;
use App\Models\ShareLink;
use App\Models\User;
use App\Services\InviteService;
use App\Support\Locales;
use App\Support\LocalizedText;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;
use Teto\HTTP\AcceptLanguage;

class ShareLinkController extends Controller
{
    /**
     * Shared with resources/js/Components/LanguageSwitcher.vue's own choice
     * of name only by convention, not by any code path in common — the
     * switcher is a plain full-navigation link (see its own header
     * comment), so it never reads or writes this cookie itself; landing on
     * whichever locale route it points at is what sets it, same as any
     * other visit here.
     */
    private const LOCALE_COOKIE = 'wtf-locale';

    private const LOCALE_COOKIE_MINUTES = 60 * 24 * 365;

    public function __construct(private readonly InviteService $invites) {}

    /**
     * $token is optional: /free and /{locale}/free (no token segment at
     * all) resolve here too, same as a token that matches nothing — both
     * render the "link expired" state (see render() below) rather than a
     * bare 404, so an old bookmark or a mistyped/regenerated-away link
     * lands on a branded page instead of the framework's generic error
     * page. Every real share link is looked up purely by its own
     * highlight_token (every link gets one at creation now — see
     * ShareLinkManagementController::store() — named after the old app's
     * calendar_highlight_tokens.token, its own name for this same public
     * identifier); there's no separate UUID-id lookup path. The content
     * key derives deterministically from that same value
     * (App\Services\Crypto\HighlightTokenKey), so there's nothing to add
     * to the URL and nowhere to redirect to for that concern specifically.
     * The locale redirect below is a separate concern and can fire before
     * the token is even looked up.
     *
     * $token is read from the route explicitly, not as a method parameter
     * — with only a `locale` default and no `{token}` segment at all (the
     * index routes), Laravel's route-parameter-to-method-parameter
     * splicing positionally handed that lone default value to the first
     * unmatched scalar parameter, silently passing 'en' in as $token
     * instead of null. Reading it via $request->route() instead sidesteps
     * that splicing entirely, the same way $locale already does below.
     */
    public function show(Request $request): InertiaResponse|RedirectResponse|Response
    {
        $token = $request->route('token');
        $locale = $request->route('locale', Locales::DEFAULT);

        // Detection (cookie or Accept-Language) only ever promotes the
        // no-prefix English path up to another locale — it never demotes an
        // explicit /{locale}/free visit back down to /free. Landing on a
        // locale-prefixed path is itself a clear, deliberate signal (a
        // shared link, a bookmark, a manual LanguageSwitcher.vue click)
        // that must never get silently overridden by a visitor's browser
        // language settings; only the ambiguous no-prefix path is worth
        // guessing at.
        if ($locale === Locales::DEFAULT) {
            $preferredLocale = $this->resolvePreferredLocale($request, $locale);

            if ($preferredLocale !== $locale) {
                Cookie::queue(self::LOCALE_COOKIE, $preferredLocale, self::LOCALE_COOKIE_MINUTES);

                $query = $request->getQueryString();
                $path = $token !== null ? "/{$preferredLocale}/free/{$token}" : "/{$preferredLocale}/free";

                // Same token, same query string, just the other locale's
                // path prefix — the browser carries over the current URL's
                // own #k=... fragment on its own (never sent to the server
                // to begin with), same as any other same-origin redirect.
                return redirect($path.($query !== null && $query !== '' ? "?{$query}" : ''));
            }
        }

        Cookie::queue(self::LOCALE_COOKIE, $locale, self::LOCALE_COOKIE_MINUTES);

        $shareLink = $token !== null ? ShareLink::where('highlight_token', $token)->first() : null;

        $response = $this->render($shareLink, $token, $locale);

        // 401, not 200 or 404 — same "was valid, isn't now" signal as
        // ShareLinkAvailabilityController's own archived-link response,
        // for the same reason: a viewer/script can tell "expired" apart
        // from an ordinary successful render without inspecting the page
        // body. Still a full Inertia response (not a plain error page) —
        // Free/Show.vue renders its "link expired" card either way (see
        // linkFound in render()'s own doc comment).
        if ($shareLink === null) {
            return $response->toResponse($request)->setStatusCode(Response::HTTP_UNAUTHORIZED);
        }

        return $response;
    }

    /**
     * By this point $locale is already fully resolved (show()'s cookie/
     * Accept-Language redirect above has already run) — LocalizedText::
     * resolve() picks the owner's own override for $locale, falling back
     * to their 'default' entry (see App\Support\LocalizedText) rather
     * than straight to the generic computed default below.
     */
    private function resolveTitle(User $owner, string $locale): string
    {
        $resolved = LocalizedText::resolve($owner->public_page_title, $locale);

        // Falls back to a translated "My Free Time" (lang/{locale}.json's
        // free.defaultTitle — the same JSON files every other /free string
        // already uses), not the owner's own name — owners can still
        // override the heading entirely via public_page_title. __() takes
        // $locale explicitly rather than relying on App::getLocale(),
        // which nothing on this request path ever sets.
        return $resolved ?? __('free.defaultTitle', [], $locale);
    }

    /**
     * $shareLink/$token are both null for a bare /free (or /{locale}/free)
     * visit, and $shareLink alone is null for a token that doesn't match
     * anything (mistyped, or a link since deleted/regenerated away) —
     * either way this renders the same "link expired" state; see
     * linkFound below, which Free/Show.vue checks before ever calling its
     * own /api/share/{token} endpoint (there being nothing to fetch,
     * unlike an archived-but-still-existing link, which that endpoint
     * itself reports via its own 401).
     */
    private function render(?ShareLink $shareLink, ?string $token, string $locale): InertiaResponse
    {
        $invite = $shareLink !== null ? Invite::where('source_share_link_id', $shareLink->id)
            ->whereNull('max_uses')
            ->whereNull('expires_at')
            ->first() : null;

        if (! $invite && $shareLink !== null) {
            $invite = $this->invites->issue(
                inviter: $shareLink->user,
                sourceShareLink: $shareLink,
            );
        }

        $owner = $shareLink?->user;

        return Inertia::render('Free/Show', [
            'token' => $token,
            'linkFound' => $shareLink !== null,
            'inviteCode' => $invite?->code,
            'ownerName' => $owner?->name,
            'locale' => $locale,
            'textDirection' => in_array($locale, Locales::RTL, true) ? 'rtl' : 'ltr',
            'pageTitle' => $owner !== null ? $this->resolveTitle($owner, $locale) : null,
            // TODO Define based on locale
            'weekStart' => $owner?->week_start ?? 1,
            // Every slot including now is a palette KEY, not a hex — the
            // frontend resolves it to an actual light/dark hex pair via
            // resources/js/free/color-palette.ts (now: now-color-presets.ts),
            // falling back to that slot's own default swatch when null.
            'colors' => [
                'accent' => $owner?->accent_color_key,
                'secondary' => $owner?->secondary_color_key,
                'free' => $owner?->free_color_key,
                'busy' => $owner?->busy_color_key,
                'work' => $owner?->work_color_key,
                'school' => $owner?->school_color_key,
                'sleep' => $owner?->sleep_color_key,
                'highlighted' => $owner?->highlight_color_key,
                'now' => $owner?->now_color_key,
            ],
            // Same KEY-not-render-value split as colors above — see
            // resources/js/free/icon-palette.ts's resolveIcon().
            'icons' => [
                'free' => $owner?->free_icon_key,
                'busy' => $owner?->busy_icon_key,
                'work' => $owner?->work_icon_key,
                'school' => $owner?->school_icon_key,
                'sleep' => $owner?->sleep_icon_key,
                'highlighted' => $owner?->highlight_icon_key,
            ],
        ]);
    }

    /**
     * Only ever called for the no-prefix (English) route — see show()'s
     * guard above; visiting /hu/free/... never reaches this method at all,
     * so its result can only ever promote /free up to /hu, never demote.
     * A stored cookie preference always wins once it exists — set on every
     * visit here (see show() above), whether that visit arrived via this
     * very redirect, a manual LanguageSwitcher.vue click, or a share link
     * the owner copied in whichever locale they happened to be viewing —
     * so detection only ever runs once per visitor and a manual switch back
     * sticks instead of being re-guessed on the next visit. Absent that,
     * Accept-Language gets one guess; anything else (no clear preference, a
     * browser sending nothing this app supports) falls back to whatever
     * locale the URL itself already asked for, i.e. no redirect at all.
     *
     * Accept-Language parsing is Teto\HTTP\AcceptLanguage (zonuexe/
     * http-accept-language), not Symfony/Laravel's own Request::
     * getLanguages()/getPreferredLanguage() — same package this app's own
     * SledgeHammerTime sibling project uses for the same job, properly
     * BCP-47-aware (via ext-intl's Locale::parseLocale()) and q-value
     * sorted, rather than Symfony's simpler wildcard-only matching.
     */
    private function resolvePreferredLocale(Request $request, string $routeLocale): string
    {
        $cookie = $request->cookie(self::LOCALE_COOKIE);

        if ($cookie !== null && Locales::isValid($cookie)) {
            return $cookie;
        }

        foreach (AcceptLanguage::get($request->header('Accept-Language', '')) as $tag) {
            if (Locales::isValid($tag['language'])) {
                return $tag['language'];
            }
        }

        return $routeLocale;
    }
}
