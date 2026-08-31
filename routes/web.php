<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\CalendarPreviewController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InviteController;
use App\Http\Controllers\ShareLinkController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Public share-link view (§4, §0.4). Full build in Stage 6 — for now this
// hosts the "create your own" invite CTA described in Stage 3.
Route::get('/free/{shareLink}', [ShareLinkController::class, 'show'])->name('share-links.show');

Route::middleware('guest')->group(function () {
    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store']);

    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);

    Route::get('/two-factor-challenge', [TwoFactorController::class, 'challenge'])
        ->name('two-factor.challenge');
    Route::post('/two-factor-challenge', [TwoFactorController::class, 'verifyChallenge']);
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/two-factor', [TwoFactorController::class, 'setup'])->name('two-factor.setup');
    Route::post('/two-factor/confirm', [TwoFactorController::class, 'confirm'])
        ->name('two-factor.confirm');
    Route::delete('/two-factor', [TwoFactorController::class, 'disable'])->name('two-factor.disable');

    Route::get('/invites', [InviteController::class, 'index'])->name('invites.index');
    Route::post('/invites', [InviteController::class, 'store'])->name('invites.store');
    Route::delete('/invites/{invite}', [InviteController::class, 'destroy'])->name('invites.destroy');

    // §5.2 — owner-only, never persists anything. See CalendarPreviewController's doc comment.
    Route::post('/settings/calendar/preview', CalendarPreviewController::class)
        ->name('settings.calendar.preview');
});
