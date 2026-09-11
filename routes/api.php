<?php

use App\Http\Controllers\Api\ShareLinkAvailabilityController;
use App\Http\Controllers\Api\ShareLinkVisitController;
use Illuminate\Support\Facades\Route;

// Public, unauthenticated — §5.3. Stateless: no session, no CSRF. Serves
// ciphertext only; recompute is triggered on-demand from staleness, never
// on a schedule. {token}, not Eloquent route-model-binding — see the
// controller's doc comment (a legacy token isn't a model key).
Route::get('/share/{token}', [ShareLinkAvailabilityController::class, 'show'])
    ->name('api.share-links.show');

// Public, unauthenticated — fired once per real page view from
// Free/Show.vue. IP-throttled the same as every other unauthenticated
// share-link surface (see AppServiceProvider::boot()).
Route::post('/share/{token}/visits', [ShareLinkVisitController::class, 'store'])
    // Web is needed to be able to resolve the currently logged-in user
    // (Maybe this should move into a regular web endpoint?)
    ->middleware(['throttle:share-link-visit', 'web'])
    ->name('api.share-links.visits.store');

// Owner-only: lets the owner force a recompute from their own /free
// preview. Needs 'web' for the same reason /visits does — resolving the
// logged-in user from the session — the controller itself does the
// ownership check (401/403, not a redirect, since this is a JSON caller).
// throttle:30,1 is just defense-in-depth; the real per-link rate limit is
// the cache-staleness check inside the controller.
Route::post('/share/{token}/refresh', [ShareLinkAvailabilityController::class, 'refresh'])
    ->middleware(['throttle:30,1', 'web'])
    ->name('api.share-links.refresh');
