<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RecomputeShareLinkAvailability;
use App\Models\ShareLink;
use Illuminate\Http\JsonResponse;

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

    public function show(ShareLink $shareLink): JsonResponse
    {
        if ($shareLink->archived) {
            return response()->json(['error' => 'This share link has been archived.'], 404);
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
        ]);
    }
}
