<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RecomputeShareLinkAvailability;
use App\Models\ShareLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * §5.3's public API: serves whatever's cached, and triggers a recompute
 * when it's stale or missing — no scheduled/cron job, purely on-demand from
 * request traffic. No computation ever happens synchronously in this
 * request; it just reads (and possibly refreshes) share_link_cache.
 */
class ShareLinkAvailabilityController extends Controller
{
    /** How long a cached result is served before a request triggers a refresh. */
    private const CACHE_TTL_MINUTES = 15;

    /**
     * While a result is still pending (no cache row at all yet), the
     * frontend polls this endpoint roughly every 2 seconds — without a
     * guard, every single one of those hits would attempt another dispatch
     * for the whole time the first fetch+compute is in flight. The job
     * itself is ShouldBeUnique, so those extra dispatches were always
     * getting collapsed rather than actually running twice, but "collapsed"
     * still costs a lock-acquisition query each time, and piling that up
     * every 2 seconds for as long as an initial fetch takes is needless
     * pressure on whatever backs the lock (here, the `database` cache
     * store — no Redis-speed atomic lock to fall back on). Cache::add is
     * itself atomic, so only the poll that actually wins gets to dispatch;
     * every other poll in this window just serves the still-pending status.
     */
    private const DISPATCH_DEBOUNCE_SECONDS = 30;

    /**
     * Plain string token, not Eloquent route-model-binding — same reasoning
     * as ShareLinkController::show: a legacy token (§0.5) isn't a model key,
     * so implicit binding (which only ever looks up by `id`) would 404 on
     * every migrated link.
     */
    public function show(string $token): JsonResponse
    {
        $shareLink = Str::isUuid($token) ? ShareLink::find($token) : null;
        $shareLink ??= ShareLink::where('legacy_token', $token)->first();

        if ($shareLink === null) {
            abort(Response::HTTP_NOT_FOUND);
        }

        if ($shareLink->archived) {
            // 401, not 404: the link *was* valid — this is the "link
            // expired" signal the frontend renders a distinct state for,
            // matching the source app's convention, not "never existed."
            return response()->json(['error' => 'This share link has expired.'], 401);
        }

        $cache = $shareLink->cache;
        $isStale = $cache === null || $cache->encrypted_at->lt(now()->subMinutes(self::CACHE_TTL_MINUTES));

        if ($isStale && Cache::add("recompute-dispatched:{$shareLink->id}", true, self::DISPATCH_DEBOUNCE_SECONDS)) {
            RecomputeShareLinkAvailability::dispatch($shareLink->id);
        }

        if ($cache === null) {
            return response()->json([
                'status' => 'pending',
                'timezone' => $shareLink->user->timezone,
            ], 202);
        }

        return response()->json([
            'status' => 'ready',
            'ciphertext' => $cache->ciphertext,
            'computed_range_start' => $cache->computed_range_start->toIso8601String(),
            'computed_range_end' => $cache->computed_range_end->toIso8601String(),
            'stale' => $isStale,
            // Plaintext, not sensitive — same tier as availability_settings
            // (schedule shape, not content). Lets the viewer compare their
            // own timezone against the owner's, same as the source app.
            'timezone' => $shareLink->user->timezone,
        ]);
    }
}
