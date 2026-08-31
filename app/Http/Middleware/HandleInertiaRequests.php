<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\ColorPalette;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'appName' => config('app.name'),
            // Same reasoning as the old partials.header Blade composer this
            // replaces: SiteHeader.vue needs this on every page that
            // renders it, and computing it once here beats every
            // controller having to remember to pass it.
            'isFirstUser' => User::isFirstUser(),
            // The client only ever sends a KEY back (SettingsController
            // validates every *_color_key against ColorPalette::KEYS) —
            // never a hex. This is the one and only place the actual
            // light/dark hex values are defined; the frontend resolves
            // keys to hex purely from this shared prop (seeded once at
            // boot into color-palette.ts — see resources/js/app.ts), never
            // from a hardcoded copy.
            'colorPalette' => [
                'swatches' => ColorPalette::forFrontend(),
                'defaults' => ColorPalette::DEFAULT_KEYS,
            ],
            'auth' => [
                'user' => $request->user() ? [
                    'name' => $request->user()->name,
                    'avatarUrl' => $request->user()->gravatarUrl(),
                    // Reflects the owner's own public-page accent/secondary
                    // colors (Settings) across their dashboard too, applied
                    // by DashboardLayout.vue the same way Free/Show.vue's
                    // rootStyle already applies them on the public page
                    // (--wtf-accent/--wtf-accent-rgb, --wtf-text-muted) —
                    // not sensitive, same tier as the other public-page
                    // display settings (§0.2, not §0.1). Palette KEYs, not
                    // hex — see resources/js/free/color-palette.ts.
                    'accentColorKey' => $request->user()->accent_color_key,
                    'secondaryColorKey' => $request->user()->secondary_color_key,
                ] : null,
            ],
            'flash' => [
                'status' => $request->session()->get('status'),
                'recoveryCodes' => $request->session()->get('recoveryCodes'),
            ],
        ];
    }
}
