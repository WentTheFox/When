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
    ->middleware('throttle:share-link-visit')
    ->name('api.share-links.visits.store');
