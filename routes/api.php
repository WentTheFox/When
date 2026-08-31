<?php

use App\Http\Controllers\Api\ShareLinkAvailabilityController;
use Illuminate\Support\Facades\Route;

// Public, unauthenticated — §5.3. Stateless: no session, no CSRF. Serves
// ciphertext only; recompute is triggered on-demand from staleness, never
// on a schedule.
Route::get('/share/{shareLink}', [ShareLinkAvailabilityController::class, 'show'])
    ->name('api.share-links.show');
