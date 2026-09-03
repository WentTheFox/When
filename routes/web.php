<?php

use App\Http\Controllers\AboutController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\CalendarPreviewController;
use App\Http\Controllers\Dashboard\AccountController;
use App\Http\Controllers\Dashboard\AccountDeletionController;
use App\Http\Controllers\Dashboard\AccountExportController;
use App\Http\Controllers\Dashboard\ActivityRoleController;
use App\Http\Controllers\Dashboard\ConnectionAttributeDefinitionController;
use App\Http\Controllers\Dashboard\ConnectionController;
use App\Http\Controllers\Dashboard\ConnectionEdgeController;
use App\Http\Controllers\Dashboard\ConnectionsGraphController;
use App\Http\Controllers\Dashboard\ConnectionSourceCategoryController;
use App\Http\Controllers\Dashboard\ConnectionSourceController;
use App\Http\Controllers\Dashboard\SettingsController;
use App\Http\Controllers\Dashboard\ShareLinkManagementController;
use App\Http\Controllers\Dashboard\SleepExceptionController;
use App\Http\Controllers\Dashboard\VaultController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InviteController;
use App\Http\Controllers\ShareLinkController;
use App\Support\Locales;
use Illuminate\Support\Facades\Route;

// `/` is a pure dispatcher (see AboutController's own doc comment) — the
// content itself is only ever served from /about.
Route::get('/', [AboutController::class, 'redirectHome']);
Route::get('/about', [AboutController::class, 'show']);

// The security/data-handling explanation used to be its own page at this
// URL (§0.2's honesty commitment, Stage 8) — now folded into /about (see
// AboutController) alongside the welcome copy, so old links/bookmarks still
// land somewhere rather than 404ing.
Route::redirect('/security', '/about');

// Public share-link view (§4, §0.4, §0.5/Stage 5). Full build in Stage 6 —
// for now this hosts the "create your own" invite CTA described in Stage 3,
// plus the highlight_token resolution described in ShareLinkController's
// doc comment. {token} (not {shareLink}) since it's a plain string, looked
// up by highlight_token inside the controller — never Eloquent
// route-model-binding, since it isn't a model key. The index routes (no
// {token} segment) resolve here too, always rendering the "link expired"
// state — see ShareLinkController::render()'s doc comment. Locale is part
// of the path, not a query param or Accept-Language guess — /free/... is
// always English, /{locale}/free/... (one route per App\Support\Locales::
// codes() entry other than 'en', the no-prefix default) is always that
// locale. Every one of these hits the same action; the locale default
// tells the controller which.
Route::middleware('throttle:share-link-view')->group(function () {
    Route::get('/free', [ShareLinkController::class, 'show'])
        ->name('share-links.index')
        ->defaults('locale', Locales::DEFAULT);
    Route::get('/free/{token}', [ShareLinkController::class, 'show'])
        ->name('share-links.show')
        ->defaults('locale', Locales::DEFAULT);

    foreach (Locales::codes() as $locale) {
        if ($locale === Locales::DEFAULT) {
            continue;
        }
        Route::prefix($locale)->group(function () use ($locale) {
            Route::get('/free', [ShareLinkController::class, 'show'])
                ->name("share-links.index.{$locale}")
                ->defaults('locale', $locale);
            Route::get('/free/{token}', [ShareLinkController::class, 'show'])
                ->name("share-links.show.{$locale}")
                ->defaults('locale', $locale);
        });
    }
});

// Deliberately NOT inside the guest-only group below: it's also called by
// already-authenticated pages that need the account's own id-based verifier
// salt (ConfirmPasswordModal.vue, Account.vue's change-master-password flow)
// — under `guest`, the middleware itself would 302-redirect those calls to
// /dashboard before AuthenticatedSessionController::lookup() ever runs,
// handing the caller an HTML redirect body instead of the {id, saltVersion}
// JSON it expects. The lookup itself carries no session-specific
// information either way (its whole point is to work for a not-yet-
// authenticated caller), so nothing is lost by allowing both.
Route::middleware('throttle:login-lookup')->group(function () {
    Route::post('/login/lookup', [AuthenticatedSessionController::class, 'lookup'])->name('login.lookup');
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

    Route::get('/two-factor-challenge', [TwoFactorController::class, 'challenge'])
        ->name('two-factor.challenge');
    Route::post('/two-factor-challenge', [TwoFactorController::class, 'verifyChallenge']);
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::post('/account/migrate-verifier', [AuthenticatedSessionController::class, 'migrateVerifier'])
        ->name('account.migrate-verifier');

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard/stats/availability', [DashboardController::class, 'statsAvailability'])
        ->name('dashboard.stats.availability');

    Route::get('/dashboard/account', [AccountController::class, 'edit'])->name('dashboard.account');
    Route::patch('/dashboard/account/name', [AccountController::class, 'updateName'])
        ->name('dashboard.account.name.update');
    Route::patch('/dashboard/account/email', [AccountController::class, 'updateEmail'])
        ->name('dashboard.account.email.update');
    Route::put('/dashboard/account/password', [AccountController::class, 'updatePassword'])
        ->name('dashboard.account.password.update');

    // Both require re-confirming the master password (ConfirmsPassword) —
    // see AccountExportController/AccountDeletionController's own doc
    // comments. Export is additionally throttled since it's the heaviest
    // self-service action a caller can trigger.
    Route::post('/dashboard/account/export', [AccountExportController::class, 'store'])
        ->name('dashboard.account.export')
        ->middleware('throttle:account-data-export');
    Route::delete('/dashboard/account', [AccountDeletionController::class, 'destroy'])
        ->name('dashboard.account.destroy');

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

    Route::post('/settings/activity-roles', [ActivityRoleController::class, 'store'])
        ->name('activity-roles.store');
    Route::patch('/settings/activity-roles/{activityRole}', [ActivityRoleController::class, 'update'])
        ->name('activity-roles.update');
    Route::delete('/settings/activity-roles/{activityRole}', [ActivityRoleController::class, 'destroy'])
        ->name('activity-roles.destroy');

    Route::get('/dashboard/share-links', [ShareLinkManagementController::class, 'index'])
        ->name('dashboard.share-links');
    Route::post('/dashboard/share-links', [ShareLinkManagementController::class, 'store'])
        ->name('dashboard.share-links.store');
    Route::patch('/dashboard/share-links/{shareLink}', [ShareLinkManagementController::class, 'update'])
        ->name('dashboard.share-links.update');
    Route::delete('/dashboard/share-links/{shareLink}', [ShareLinkManagementController::class, 'destroy'])
        ->name('dashboard.share-links.destroy');
    Route::post('/dashboard/share-links/{shareLink}/regenerate-token', [ShareLinkManagementController::class, 'regenerateToken'])
        ->name('dashboard.share-links.regenerate-token');
    Route::get('/dashboard/share-links/export', [ShareLinkManagementController::class, 'export'])
        ->name('dashboard.share-links.export');
    Route::post('/dashboard/share-links/import', [ShareLinkManagementController::class, 'import'])
        ->name('dashboard.share-links.import');

    Route::get('/dashboard/connections', [ConnectionController::class, 'index'])
        ->name('dashboard.connections');
    Route::get('/dashboard/connections/search-index', [ConnectionController::class, 'searchIndex'])
        ->name('dashboard.connections.search-index');
    Route::get('/dashboard/connections/graph', ConnectionsGraphController::class)
        ->name('dashboard.connections.graph');
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
