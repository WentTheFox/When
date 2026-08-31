<?php

namespace App\Http\Controllers;

use App\Models\Invite;
use App\Models\ShareLink;
use App\Models\User;
use App\Services\InviteService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

class ShareLinkController extends Controller
{
    public function __construct(private readonly InviteService $invites) {}

    /**
     * Handles both the current share-link id shape (UUID) and legacy
     * pre-migration tokens (§0.5/Stage 5) under one route and one path
     * shape — no redirect involved for either. A migrated link's content
     * key is derived deterministically from the token itself (§0.5,
     * App\Services\Crypto\LegacyShareLinkKey) rather than delivered via a
     * fragment, so there's nothing to add to the URL and nowhere to
     * redirect to: the token the visitor already has is everything both
     * the client and this server-side view need. WentTheNuxt's (the
     * sibling repo's) entire role in this migration is a blanket
     * same-path domain redirect; it needs no per-token data from this app
     * at all, since the token in the URL never changes between the two
     * apps.
     */
    public function show(Request $request, string $token): InertiaResponse
    {
        $locale = $request->route('locale', 'en');

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
     * The path (/free vs /hu/free) is the only thing that decides locale —
     * no query param, no Accept-Language guessing. Falling back to the
     * other locale's title (if set) beats falling straight to the generic
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
            'keyProtection' => $shareLink->key_protection,
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
}
