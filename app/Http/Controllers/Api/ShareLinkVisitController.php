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
 * view of a resolvable link, recording only a timestamp and the visitor's
 * own browser-reported IANA timezone (see the migration's doc comment for
 * why that's plaintext, not ciphertext). Plain string {token} lookup, not
 * route-model binding — same reasoning as every other public share-link
 * endpoint.
 */
class ShareLinkVisitController extends Controller
{
    public function store(Request $request, string $token): JsonResponse
    {
        $shareLink = ShareLink::where('highlight_token', $token)->first();

        if ($shareLink === null || $shareLink->archived) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $authUser = Auth::user();
        if ($authUser !== null && $shareLink->user_id === $authUser->id) {
            // Don't track the user's own page views of their own share links
            return response()->json([
                'note' => 'Page views for your own share links are not tracked to let you view your share links without inflating view counts. If you want to test the functionality, log out before opening the share link.'
            ], Response::HTTP_ACCEPTED);
        }

        $data = $request->validate([
            'timezone' => ['required', 'string', 'max:100', 'timezone'],
        ]);

        ShareLinkVisit::create([
            'share_link_id' => $shareLink->id,
            'timezone' => $data['timezone'],
        ]);

        return response()->json(null, Response::HTTP_CREATED);
    }
}
