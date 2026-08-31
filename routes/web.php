<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\CalendarPreviewController;
use App\Http\Controllers\Dashboard\AccountController;
use App\Http\Controllers\Dashboard\ConnectionAttributeDefinitionController;
use App\Http\Controllers\Dashboard\ConnectionController;
use App\Http\Controllers\Dashboard\ConnectionEdgeController;
use App\Http\Controllers\Dashboard\ConnectionSourceCategoryController;
use App\Http\Controllers\Dashboard\ConnectionSourceController;
use App\Http\Controllers\Dashboard\SettingsController;
use App\Http\Controllers\Dashboard\ShareLinkManagementController;
use App\Http\Controllers\Dashboard\SleepExceptionController;
use App\Http\Controllers\Dashboard\VaultController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InviteController;
use App\Http\Controllers\SecurityPageController;
use App\Http\Controllers\ShareLinkController;
use App\Http\Controllers\WelcomeController;
use Illuminate\Support\Facades\Route;

Route::get('/', [WelcomeController::class, 'show']);

// Public threat-model/security page (§0.2's honesty commitment, Stage 8) —
// no auth required, linked from the footer on every page.
Route::get('/security', [SecurityPageController::class, 'show'])->name('security.show');

// Public share-link view (§4, §0.4, §0.5/Stage 5). Full build in Stage 6 —
// for now this hosts the "create your own" invite CTA described in Stage 3,
// plus the legacy-token resolution described in ShareLinkController's doc
// comment. {token} (not {shareLink}) since it's a plain string, resolving
// either a UUID share-link id or a legacy token inside the controller —
// never Eloquent route-model-binding, since a legacy token isn't a model key.
// Locale is part of the path, not a query param or Accept-Language guess —
// /free/{token} is always English, /hu/free/{token} is always Hungarian.
// Both hit the same action; the locale default tells the controller which.
Route::middleware('throttle:share-link-view')->group(function () {
    Route::get('/free/{token}', [ShareLinkController::class, 'show'])
        ->name('share-links.show')
        ->defaults('locale', 'en');
    Route::get('/hu/free/{token}', [ShareLinkController::class, 'show'])
        ->name('share-links.show.hu')
        ->defaults('locale', 'hu');
});

Route::middleware('guest')->group(function () {
    // Both the GET (which previews *who* invited you, findValid) and the
    // POST (which actually redeems the code) can be used to brute-force
    // guess invite codes, so both are throttled — not just the redeeming
    // action.
    Route::middleware('throttle:invite-redemption')->group(function () {
        Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
        Route::post('/register', [RegisteredUserController::class, 'store']);
    });

    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);
    Route::middleware('throttle:login-lookup')->group(function () {
        Route::post('/login/lookup', [AuthenticatedSessionController::class, 'lookup'])->name('login.lookup');
    });

    Route::get('/two-factor-challenge', [TwoFactorController::class, 'challenge'])
        ->name('two-factor.challenge');
    Route::post('/two-factor-challenge', [TwoFactorController::class, 'verifyChallenge']);
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::post('/account/migrate-verifier', [AuthenticatedSessionController::class, 'migrateVerifier'])
        ->name('account.migrate-verifier');

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/dashboard/account', [AccountController::class, 'edit'])->name('dashboard.account');
    Route::patch('/dashboard/account/name', [AccountController::class, 'updateName'])
        ->name('dashboard.account.name.update');
    Route::patch('/dashboard/account/email', [AccountController::class, 'updateEmail'])
        ->name('dashboard.account.email.update');

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

    // Vault ciphertext round-trip (§0.3) for the dashboard's own vault-unlock
    // flow. See VaultController's doc comment.
    Route::get('/dashboard/vault', [VaultController::class, 'show'])->name('dashboard.vault.show');
    Route::patch('/dashboard/vault', [VaultController::class, 'update'])->name('dashboard.vault.update');

    Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::patch('/settings', [SettingsController::class, 'update'])->name('settings.update');
    // Its own action (not folded into the main settings save) so a pending,
    // not-yet-previewed calendar URL can never block saving anything else —
    // see SettingsController::updateCalendarUrl's doc comment.
    Route::patch('/settings/calendar-url', [SettingsController::class, 'updateCalendarUrl'])
        ->name('settings.calendar-url.update');

    Route::post('/settings/sleep-exceptions', [SleepExceptionController::class, 'store'])
        ->name('sleep-exceptions.store');
    Route::delete('/settings/sleep-exceptions/{sleepException}', [SleepExceptionController::class, 'destroy'])
        ->name('sleep-exceptions.destroy');

    Route::get('/dashboard/share-links', [ShareLinkManagementController::class, 'index'])
        ->name('dashboard.share-links');
    Route::post('/dashboard/share-links', [ShareLinkManagementController::class, 'store'])
        ->name('dashboard.share-links.store');
    Route::patch('/dashboard/share-links/{shareLink}', [ShareLinkManagementController::class, 'update'])
        ->name('dashboard.share-links.update');
    Route::post('/dashboard/share-links/{shareLink}/regenerate-key', [ShareLinkManagementController::class, 'regenerateKey'])
        ->name('dashboard.share-links.regenerate-key');
    Route::get('/dashboard/share-links/{shareLink}/url', [ShareLinkManagementController::class, 'url'])
        ->name('dashboard.share-links.url');
    Route::get('/dashboard/share-links/export', [ShareLinkManagementController::class, 'export'])
        ->name('dashboard.share-links.export');
    Route::post('/dashboard/share-links/import', [ShareLinkManagementController::class, 'import'])
        ->name('dashboard.share-links.import');

    Route::get('/dashboard/connections', [ConnectionController::class, 'index'])
        ->name('dashboard.connections');
    Route::get('/dashboard/connections/search-index', [ConnectionController::class, 'searchIndex'])
        ->name('dashboard.connections.search-index');
    Route::post('/dashboard/connections', [ConnectionController::class, 'store'])
        ->name('dashboard.connections.store');
    Route::patch('/dashboard/connections/{connection}', [ConnectionController::class, 'update'])
        ->name('dashboard.connections.update');
    Route::delete('/dashboard/connections/{connection}', [ConnectionController::class, 'destroy'])
        ->name('dashboard.connections.destroy');

    Route::post('/dashboard/connection-sources', [ConnectionSourceController::class, 'store'])
        ->name('dashboard.connection-sources.store');
    Route::patch('/dashboard/connection-sources/{source}', [ConnectionSourceController::class, 'update'])
        ->name('dashboard.connection-sources.update');
    Route::delete('/dashboard/connection-sources/{source}', [ConnectionSourceController::class, 'destroy'])
        ->name('dashboard.connection-sources.destroy');

    Route::post('/dashboard/connection-source-categories', [ConnectionSourceCategoryController::class, 'store'])
        ->name('dashboard.connection-source-categories.store');
    Route::patch('/dashboard/connection-source-categories/{category}', [ConnectionSourceCategoryController::class, 'update'])
        ->name('dashboard.connection-source-categories.update');
    Route::delete('/dashboard/connection-source-categories/{category}', [ConnectionSourceCategoryController::class, 'destroy'])
        ->name('dashboard.connection-source-categories.destroy');

    Route::post('/dashboard/connection-attribute-definitions', [ConnectionAttributeDefinitionController::class, 'store'])
        ->name('dashboard.connection-attribute-definitions.store');
    Route::delete('/dashboard/connection-attribute-definitions/{definition}', [ConnectionAttributeDefinitionController::class, 'destroy'])
        ->name('dashboard.connection-attribute-definitions.destroy');

    Route::post('/dashboard/connection-edges', [ConnectionEdgeController::class, 'store'])
        ->name('dashboard.connection-edges.store');
    Route::delete('/dashboard/connection-edges/{edge}', [ConnectionEdgeController::class, 'destroy'])
        ->name('dashboard.connection-edges.destroy');
});
