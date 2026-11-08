<?php

use App\Http\Controllers\AboutController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\CalendarPreviewController;
use App\Http\Controllers\Dashboard\AccountController;
use App\Http\Controllers\Dashboard\AccountDeletionController;
use App\Http\Controllers\Dashboard\AccountExportController;
use App\Http\Controllers\Dashboard\ActivityLocalizationController;
use App\Http\Controllers\Dashboard\ConnectionAttributeDefinitionController;
use App\Http\Controllers\Dashboard\ConnectionController;
use App\Http\Controllers\Dashboard\ConnectionsGraphController;
use App\Http\Controllers\Dashboard\ConnectionShareLinkController;
use App\Http\Controllers\Dashboard\ConnectionSourceCategoryController;
use App\Http\Controllers\Dashboard\ConnectionSourceController;
use App\Http\Controllers\Dashboard\DiscordController;
use App\Http\Controllers\Dashboard\ListOrderController;
use App\Http\Controllers\Dashboard\PasskeyController;
use App\Http\Controllers\Dashboard\SettingsController;
use App\Http\Controllers\Dashboard\SleepExceptionController;
use App\Http\Controllers\Dashboard\VaultController;
use App\Http\Controllers\Dashboard\VrcxController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DevMockCalendarController;
use App\Http\Controllers\DevMockVrcxController;
use App\Http\Controllers\InviteController;
use App\Http\Controllers\ShareLinkController;
use App\Support\Locales;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

// `/` is a pure dispatcher (see AboutController's own doc comment) — the
// content itself is only ever served from /about.
Route::get('/', [AboutController::class, 'redirectHome']);
Route::get('/about', [AboutController::class, 'show']);

// Deploy health check (deploy.conf's health_check): read-only dependency probes, safe to hit during the
// read-only window. Unlike the framework's own /up this actually touches the database and cache.
Route::get('/status', function () {
    $checks = [];
    foreach (['database' => fn () => DB::select('select 1'), 'cache' => fn () => Cache::get('health-check')] as $name => $check) {
        try {
            $check();
            $checks[$name] = true;
        } catch (Throwable) {
            $checks[$name] = false;
        }
    }
    $healthy = ! in_array(false, $checks, true);

    return response()->json(['status' => $healthy ? 'ok' : 'error', 'checks' => $checks], $healthy ? 200 : 503);
})->name('status');

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
        Route::get('/invite/{token}', [InviteController::class, 'fromShareLink'])->name('invites.from-share-link');
        Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
        Route::post('/register', [RegisteredUserController::class, 'store']);
    });

    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);

    Route::get('/two-factor-challenge', [TwoFactorController::class, 'challenge'])
        ->name('two-factor.challenge');
    Route::post('/two-factor-challenge', [TwoFactorController::class, 'verifyChallenge']);
    Route::middleware('throttle:two-factor-passkey')->group(function () {
        Route::post('/two-factor-challenge/passkey/options', [TwoFactorController::class, 'passkeyOptions'])
            ->name('two-factor.passkey.options');
        Route::post('/two-factor-challenge/passkey', [TwoFactorController::class, 'verifyPasskey'])
            ->name('two-factor.passkey.verify');
    });
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

    // Both mutating steps re-confirm the master password (ConfirmsPassword) —
    // see PasskeyController's doc comment.
    Route::post('/dashboard/account/passkeys/options', [PasskeyController::class, 'options'])
        ->name('dashboard.account.passkeys.options');
    Route::post('/dashboard/account/passkeys', [PasskeyController::class, 'store'])
        ->name('dashboard.account.passkeys.store');
    Route::delete('/dashboard/account/passkeys/{passkey}', [PasskeyController::class, 'destroy'])
        ->name('dashboard.account.passkeys.destroy');

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

    // VRChat (VRCX) connection: credentials + field choice, manual sync, and read-backs.
    Route::patch('/settings/vrcx', [VrcxController::class, 'update'])->name('settings.vrcx.update');
    Route::delete('/settings/vrcx', [VrcxController::class, 'destroy'])->name('settings.vrcx.destroy');
    Route::post('/settings/vrcx/sync', [VrcxController::class, 'syncNow'])->name('settings.vrcx.sync');
    Route::get('/dashboard/vrchat', [VrcxController::class, 'summary'])->name('dashboard.vrchat');
    Route::get('/dashboard/vrchat/friends', [VrcxController::class, 'pendingFriends'])->name('dashboard.vrchat.friends');
    Route::post('/dashboard/vrchat/notes/imported', [VrcxController::class, 'notesImported'])->name('dashboard.vrchat.notes.imported');
    Route::post('/dashboard/vrchat/friends/complete', [VrcxController::class, 'completeFriends'])->name('dashboard.vrchat.friends.complete');
    Route::post('/dashboard/connections/notes-field', [ConnectionController::class, 'notesField'])->name('dashboard.connections.notes-field');
    Route::post('/dashboard/connections/relationships-field', [ConnectionController::class, 'relationshipsField'])->name('dashboard.connections.relationships-field');
    Route::post('/dashboard/connections/{connection}/vrchat/sync', [VrcxController::class, 'syncConnection'])
        ->name('dashboard.connections.vrchat.sync');
    Route::get('/dashboard/connections/{connection}/vrchat/stays', [VrcxController::class, 'stays'])
        ->name('dashboard.connections.vrchat.stays');

    // Discord: OAuth2 grant, manual sync, and the friends waiting to be linked to connections.
    Route::get('/settings/discord/connect', [DiscordController::class, 'connect'])->name('settings.discord.connect');
    Route::get('/settings/discord/callback', [DiscordController::class, 'callback'])->name('settings.discord.callback');
    Route::delete('/settings/discord', [DiscordController::class, 'destroy'])->name('settings.discord.destroy');
    Route::post('/settings/discord/sync', [DiscordController::class, 'syncNow'])
        ->middleware('throttle:6,1')->name('settings.discord.sync');
    Route::post('/settings/discord/import', [DiscordController::class, 'importRelationships'])
        ->middleware('throttle:6,1')->name('settings.discord.import');
    Route::post('/dashboard/discord/friends/{friend}/refresh', [DiscordController::class, 'refreshFriend'])
        ->middleware('throttle:30,1')->name('dashboard.discord.friends.refresh');
    Route::get('/dashboard/discord', [DiscordController::class, 'summary'])->name('dashboard.discord');
    Route::post('/dashboard/connections/{connection}/discord/lookup', [DiscordController::class, 'lookup'])
        ->middleware('throttle:10,1')->name('dashboard.connections.discord.lookup');
    Route::get('/dashboard/discord/friends', [DiscordController::class, 'pendingFriends'])->name('dashboard.discord.friends');
    Route::post('/dashboard/discord/friends/complete', [DiscordController::class, 'completeFriends'])->name('dashboard.discord.friends.complete');

    Route::post('/settings/sleep-exceptions', [SleepExceptionController::class, 'store'])
        ->name('sleep-exceptions.store');
    Route::delete('/settings/sleep-exceptions/{sleepException}', [SleepExceptionController::class, 'destroy'])
        ->name('sleep-exceptions.destroy');

    Route::post('/settings/activity-localization', [ActivityLocalizationController::class, 'store'])
        ->name('activity-localizations.store');
    Route::patch('/settings/activity-localization/{activityLocalization}', [ActivityLocalizationController::class, 'update'])
        ->name('activity-localizations.update');
    Route::delete('/settings/activity-localization/{activityLocalization}', [ActivityLocalizationController::class, 'destroy'])
        ->name('activity-localizations.destroy');

    // The standalone share-links page is gone: links live on their connection.
    Route::redirect('/dashboard/share-links', '/dashboard/connections')
        ->name('dashboard.share-links');

    Route::post('/dashboard/connections/{connection}/share-link', [ConnectionShareLinkController::class, 'store'])
        ->name('dashboard.connections.share-link.store');
    Route::delete('/dashboard/connections/{connection}/share-link', [ConnectionShareLinkController::class, 'destroy'])
        ->name('dashboard.connections.share-link.destroy');
    Route::post('/dashboard/connections/{connection}/share-link/regenerate-token', [ConnectionShareLinkController::class, 'regenerateToken'])
        ->name('dashboard.connections.share-link.regenerate-token');
    Route::put('/dashboard/connections/{connection}/share-link/identity', [ConnectionShareLinkController::class, 'updateIdentity'])
        ->name('dashboard.connections.share-link.identity');
    Route::get('/dashboard/connections/{connection}/share-link/visits', [ConnectionShareLinkController::class, 'visits'])
        ->name('dashboard.connections.share-link.visits');
    Route::delete('/dashboard/connections/{connection}/share-link/visits', [ConnectionShareLinkController::class, 'destroyVisits'])
        ->name('dashboard.connections.share-link.visits.destroy-all');
    Route::delete('/dashboard/connections/{connection}/share-link/visits/{visit}', [ConnectionShareLinkController::class, 'destroyVisit'])
        ->name('dashboard.connections.share-link.visits.destroy');

    // Saves the order of one of the Connections page's lists (the client sorts: names are E2EE).
    Route::post('/dashboard/order/{kind}', ListOrderController::class)
        ->whereIn('kind', array_keys(ListOrderController::TABLES))
        ->name('dashboard.order');

    Route::get('/dashboard/connections', [ConnectionController::class, 'index'])
        ->name('dashboard.connections');
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
    Route::post('/dashboard/connection-attribute-definitions/{definition}/fill-identifiers', [ConnectionAttributeDefinitionController::class, 'fillIdentifiers'])
        ->name('dashboard.connection-attribute-definitions.fill-identifiers');
    Route::post('/dashboard/connection-attribute-definitions/{definition}/migrate', [ConnectionAttributeDefinitionController::class, 'migrate'])
        ->name('dashboard.connection-attribute-definitions.migrate');
    Route::post('/dashboard/connection-attribute-definitions/{definition}/clear-values', [ConnectionAttributeDefinitionController::class, 'clearValues'])
        ->name('dashboard.connection-attribute-definitions.clear-values');
    Route::post('/dashboard/connection-attribute-definitions/{definition}/rewrite-values', [ConnectionAttributeDefinitionController::class, 'rewriteValues'])
        ->name('dashboard.connection-attribute-definitions.rewrite-values');
    Route::patch('/dashboard/connection-attribute-definitions/{definition}', [ConnectionAttributeDefinitionController::class, 'update'])
        ->name('dashboard.connection-attribute-definitions.update');
    Route::delete('/dashboard/connection-attribute-definitions/{definition}', [ConnectionAttributeDefinitionController::class, 'destroy'])
        ->name('dashboard.connection-attribute-definitions.destroy');

});

// A fake owner calendar for local development (ConnectionsDemoSeeder points the demo account at it).
// Never registered in production.
if (! app()->isProduction()) {
    Route::get('/dev/mock-calendar.ics', [DevMockCalendarController::class, 'show'])->name('dev.mock-calendar');

    // A fake VRCX headless server (ConnectionsDemoSeeder points the demo account's VRCX URL at it).
    Route::prefix('/dev/mock-vrcx')->withoutMiddleware(['web'])->group(function () {
        Route::get('/api/v1/openapi.json', [DevMockVrcxController::class, 'openapi']);
        Route::post('/api/v1/users', [DevMockVrcxController::class, 'users']);
        Route::get('/api/v1/users/{id}', [DevMockVrcxController::class, 'user']);
        Route::get('/api/v1/time-together', [DevMockVrcxController::class, 'timeTogether']);
        Route::post('/api/v1/time-together', [DevMockVrcxController::class, 'timeTogether']);
        Route::get('/api/v1/friends', [DevMockVrcxController::class, 'friends']);
        Route::get('/avatar/{n}.svg', [DevMockVrcxController::class, 'avatar'])->whereNumber('n');
    });
}
