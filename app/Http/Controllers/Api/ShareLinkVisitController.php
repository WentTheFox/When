<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShareLink;
use App\Models\ShareLinkVisit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public, unauthenticated — same shape as ShareLinkAvailabilityController.
 * Fired fire-and-forget from Free/Show.vue's onMounted for every real page
 * view of a resolvable link, recording only a timestamp, the visitor's own
 * browser-reported IANA timezone, and their browser-reported locale (see the
 * migrations' doc comments for why those are encrypted-at-rest but not
 * treated as either §0.1 or §0.2 ciphertext). Plain string {token} lookup,
 * not route-model binding — same reasoning as every other public share-link
 * endpoint.
 */
class ShareLinkVisitController extends Controller
{
    /** How many of a link's most recent visits get tallied for the owner-preview timezone picker. */
    private const MAX_VISITS_FOR_TIMEZONE_TALLY = 500;

    public function store(Request $request, string $token): JsonResponse
    {
        $shareLink = ShareLink::where('highlight_token', $token)->first();

        if ($shareLink === null || $shareLink->archived) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $authUser = Auth::user();
        if ($authUser !== null && $shareLink->user_id === $authUser->id) {
            // Don't track the owner's own page views of their own share
            // links, but *do* hand back every distinct real visitor
            // timezone recorded for this link, with view counts —
            // Free/Show.vue's "our timezones match" note is otherwise
            // computed against the owner's own browser timezone whenever
            // the owner is the one viewing the page, which is trivially
            // true and tells them nothing. This lets the owner instead pick
            // a real, previously-recorded visitor timezone to verify
            // against, and a same-shaped tally of recorded visitor locales
            // (purely informational — there's no "match" concept for a
            // locale the way there is for a timezone offset). Both
            // `timezone` and `locale` are encrypted casts (see
            // ShareLinkVisit), so the grouping has to happen in PHP, not
            // SQL, after decryption — capped to the most recent
            // MAX_VISITS_FOR_TIMEZONE_TALLY rows (via the existing
            // (share_link_id, visited_at) index) so a heavily-viewed link
            // can't turn this into an unbounded table scan; an index on
            // either column itself would be useless anyway since both are
            // ciphertext.
            $recentVisits = $shareLink->visits()
                ->latest('visited_at')
                ->limit(self::MAX_VISITS_FOR_TIMEZONE_TALLY)
                ->get();

            $tally = fn (string $column) => $recentVisits
                ->countBy($column)
                ->map(fn (int $count, string $value) => [$column => $value, 'count' => $count])
                ->values()
                ->sortByDesc('count')
                ->values();

            return response()->json([
                'note' => 'Page views for your own share links are not tracked to let you view your share links without inflating view counts. If you want to test the functionality, log out before opening the share link.',
                'visitor_timezones' => $tally('timezone'),
                // A visit with no recorded locale (older rows, or a browser
                // that didn't report one) contributes nothing here rather
                // than a bogus "" entry.
                'visitor_locales' => $tally('locale')->reject(fn (array $row) => $row['locale'] === '')->values(),
            ], Response::HTTP_ACCEPTED);
        }

        $data = $request->validate([
            'timezone' => ['required', 'string', 'max:100', 'timezone'],
            'locale' => ['nullable', 'string', 'max:35'],
        ]);

        ShareLinkVisit::create([
            'share_link_id' => $shareLink->id,
            'timezone' => $data['timezone'],
            'locale' => $data['locale'] ?? null,
        ]);

        return response()->json(null, Response::HTTP_CREATED);
    }
}
