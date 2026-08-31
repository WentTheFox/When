<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RecomputeShareLinkAvailability;
use App\Models\ShareLink;
use Illuminate\Http\JsonResponse;
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

        if ($isStale) {
            RecomputeShareLinkAvailability::dispatch($shareLink->id);
        }

        if ($cache === null) {
            return response()->json([
                'status' => 'pending',
                'key_protection' => $shareLink->key_protection,
                'timezone' => $shareLink->user->timezone,
            ], 202);
        }

        return response()->json([
            'status' => 'ready',
            'ciphertext' => $cache->ciphertext,
            'key_protection' => $shareLink->key_protection,
            'wrapped_key' => $shareLink->key_protection === 'passphrase' ? $shareLink->wrapped_key : null,
            'wrap_salt' => $shareLink->key_protection === 'passphrase' ? $shareLink->wrap_salt : null,
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
