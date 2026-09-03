<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\ColorPalette;
use App\Support\IconPalette;
use App\Support\Locales;
use App\Support\NowColorPresetKey;
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
            // validates every *_color_key with Rule::enum(ColorSwatchKey::class)) —
            // never a hex. This is the one and only place the actual
            // light/dark hex values are defined; the frontend resolves
            // keys to hex purely from this shared prop (seeded once at
            // boot into color-palette.ts — see resources/js/app.ts), never
            // from a hardcoded copy.
            'colorPalette' => [
                'swatches' => ColorPalette::forFrontend(),
                'defaults' => ColorPalette::DEFAULT_KEYS,
            ],
            // Same "server hands down the curated list + default keys,
            // frontend resolves keys to render values" split as
            // colorPalette above — see IconPalette's own doc comment for
            // why the actual key -> FontAwesome-icon mapping deliberately
            // lives only in resources/js/free/icon-palette.ts, never here.
            'iconPalette' => [
                'icons' => IconPalette::forFrontend(),
                'defaults' => IconPalette::DEFAULT_KEYS,
            ],
            // Same KEY-not-render-value split as colorPalette/iconPalette
            // above — SettingsController validates now_color_key with
            // Rule::enum(NowColorPresetKey::class), the frontend resolves
            // it to a light/dark hex from this shared prop.
            'nowColorPresets' => [
                'presets' => NowColorPresetKey::forFrontend(),
                'defaultKey' => NowColorPresetKey::default()->value,
            ],
            'auth' => [
                'user' => $request->user() ? [
                    'name' => $request->user()->name,
                    'avatarUrl' => $request->user()->gravatarUrl(),
                    // Reflects the owner's own public-page accent/secondary
                    // colors (Settings) across their dashboard too, applied
                    // by DashboardLayout.vue the same way Free/Show.vue's
                    // rootStyle already applies them on the public page
                    // (--app-accent/--app-accent-rgb, --app-text-muted) —
                    // not sensitive, same tier as the other public-page
                    // display settings (§0.2, not §0.1). Palette KEYs, not
                    // hex — see resources/js/free/color-palette.ts.
                    'accentColorKey' => $request->user()->accent_color_key,
                    'secondaryColorKey' => $request->user()->secondary_color_key,
                    // Same tier/reasoning as accent/secondary above — shared
                    // globally (not just to Free/Show.vue, which gets its
                    // own copy of these via FreeController) so any
                    // dashboard-side widget that visualizes free/busy/sleep
                    // time can reuse the owner's own /free palette instead
                    // of inventing a second, unrelated color scheme.
                    'sleepColorKey' => $request->user()->sleep_color_key,
                    'busyColorKey' => $request->user()->busy_color_key,
                    'workColorKey' => $request->user()->work_color_key,
                    'schoolColorKey' => $request->user()->school_color_key,
                    'freeColorKey' => $request->user()->free_color_key,
                ] : null,
            ],
            // Single source of truth for every language the /free page and
            // owner-authored localized-text fields can be shown in — see
            // App\Support\Locales's own doc comment.
            'locales' => Locales::forFrontend(),
            'flash' => [
                'status' => $request->session()->get('status'),
                'recoveryCodes' => $request->session()->get('recoveryCodes'),
            ],
        ];
    }
}
