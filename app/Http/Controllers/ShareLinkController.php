<?php

namespace App\Http\Controllers;

use App\Models\Invite;
use App\Models\ShareLink;
use App\Models\User;
use App\Services\InviteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

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
     * Handles both the current share-link id shape (UUID) and legacy
     * pre-migration tokens (§0.5/Stage 5) under one route and one path
     * shape — no redirect involved for either (every link's content key is
     * derived deterministically from its own id/legacy_token,
     * App\Services\Crypto\LegacyShareLinkKey, so there's nothing to add to
     * the URL and nowhere to redirect to for that concern specifically).
     * The locale redirect below is a separate concern — /free vs /hu/free —
     * and can fire before either token shape is even looked at.
     */
    public function show(Request $request, string $token): InertiaResponse|RedirectResponse
    {
        $locale = $request->route('locale', 'en');

        // Detection (cookie or Accept-Language) only ever promotes the
        // no-prefix English path up to /hu — it never demotes an explicit
        // /hu visit back down to /free. Landing on /hu/free/... is itself a
        // clear, deliberate signal (a shared link, a bookmark, a manual
        // LanguageSwitcher.vue click) that must never get silently
        // overridden by a visitor's browser language settings; only the
        // ambiguous no-prefix path is worth guessing at.
        if ($locale === 'en') {
            $preferredLocale = $this->resolvePreferredLocale($request, $locale);

            if ($preferredLocale !== $locale) {
                Cookie::queue(self::LOCALE_COOKIE, $preferredLocale, self::LOCALE_COOKIE_MINUTES);

                $query = $request->getQueryString();

                // Same token, same query string, just the other locale's
                // path prefix — the browser carries over the current URL's
                // own #k=... fragment on its own (never sent to the server
                // to begin with), same as any other same-origin redirect.
                return redirect("/hu/free/{$token}".($query !== null && $query !== '' ? "?{$query}" : ''));
            }
        }

        Cookie::queue(self::LOCALE_COOKIE, $locale, self::LOCALE_COOKIE_MINUTES);

        if (Str::isUuid($token)) {
            $shareLink = ShareLink::find($token);

            if ($shareLink !== null) {
                return $this->render($shareLink, $token, $locale);
            }
        }

        $shareLink = ShareLink::where('legacy_token', $token)->first();

        if ($shareLink === null) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return $this->render($shareLink, $token, $locale);
    }

    /**
     * By this point $locale is already fully resolved (show()'s cookie/
     * Accept-Language redirect above has already run) — this only picks
     * between the owner's two title overrides. Falling back to the other
     * locale's title (if set) beats falling straight to the generic
     * default.
     */
    private function resolveTitle(User $owner, string $locale): string
    {
        $primary = $locale === 'hu' ? $owner->public_page_title_hu : $owner->public_page_title_en;
        $secondary = $locale === 'hu' ? $owner->public_page_title_en : $owner->public_page_title_hu;

        // No hardcoded "My Free Time" branding — owners can override the
        // page heading entirely; the default is computed here rather than
        // baked into the frontend so the fallback text itself stays
        // server-controlled.
        return $primary ?? $secondary ?? "{$owner->name}'s Free Time";
    }

    private function render(ShareLink $shareLink, string $token, string $locale): InertiaResponse
    {
        $invite = Invite::where('source_share_link_id', $shareLink->id)
            ->whereNull('max_uses')
            ->whereNull('expires_at')
            ->first();

        if (! $invite) {
            $invite = $this->invites->issue(
                inviter: $shareLink->user,
                sourceShareLink: $shareLink,
            );
        }

        $owner = $shareLink->user;

        return Inertia::render('Free/Show', [
            'token' => $token,
            'inviteCode' => $invite->code,
            'ownerName' => $owner->name,
            'locale' => $locale,
            'pageTitle' => $this->resolveTitle($owner, $locale),
            'weekStart' => $owner->week_start,
            // Each slot except now_color (deliberately theme-independent,
            // see dark-theme.css) is a palette KEY, not a hex — the
            // frontend resolves it to an actual light/dark hex pair via
            // resources/js/free/color-palette.ts, falling back to that
            // slot's own default swatch when null.
            'colors' => [
                'accent' => $owner->accent_color_key,
                'secondary' => $owner->secondary_color_key,
                'free' => $owner->free_color_key,
                'busy' => $owner->busy_color_key,
                'sleep' => $owner->sleep_color_key,
                'highlighted' => $owner->highlight_color_key,
                'now' => $owner->now_color,
            ],
        ]);
    }

    /**
     * Only ever called for the no-prefix (English) route — see show()'s
     * guard above; visiting /hu/free/... never reaches this method at all,
     * so its result can only ever promote /free up to /hu, never demote.
     *
     * A stored cookie preference always wins once it exists — set on every
     * visit here (see show() above), whether that visit arrived via this
     * very redirect, a manual LanguageSwitcher.vue click, or a share link
     * the owner copied in whichever locale they happened to be viewing —
     * so detection only ever runs once per visitor and a manual switch back
     * sticks instead of being re-guessed on the next visit. Absent that,
     * Accept-Language gets one guess; anything else (no clear preference,
     * a browser sending neither en nor hu) falls back to whatever locale
     * the URL itself already asked for, i.e. no redirect at all.
     */
    private function resolvePreferredLocale(Request $request, string $routeLocale): string
    {
        $cookie = $request->cookie(self::LOCALE_COOKIE);

        if (in_array($cookie, ['en', 'hu'], true)) {
            return $cookie;
        }

        // getPreferredLanguage() falls back to $locales[0] ('en') when
        // there's no Accept-Language header at all, rather than returning
        // null — that's indistinguishable from "actually prefers English"
        // unless checked for separately, and would otherwise force every
        // header-less request (most test clients, curl, some bots) onto
        // /free regardless of which locale route they actually asked for.
        if ($request->getLanguages() === []) {
            return $routeLocale;
        }

        return $request->getPreferredLanguage(['en', 'hu']) ?? $routeLocale;
    }
}
