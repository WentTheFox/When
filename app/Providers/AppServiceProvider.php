<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Stage 8 hardening: both surfaces are reachable without
        // authentication, so IP-keyed throttling is the only protection
        // against enumeration/brute-force. Invite codes and share-link
        // tokens are high-entropy, so these limits exist to slow down
        // automated guessing, not to accommodate legitimate bursts.
        RateLimiter::for('invite-redemption', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        // Share-link responses are cache-served (ShareLinkCache), so this
        // is purely an anti-enumeration ceiling, not a load-protection
        // limit — set high enough that no real viewer ever notices it.
        RateLimiter::for('share-link-view', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));
    }
}
