<?php

namespace App\Http\Middleware;

use App\Models\User;
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
            'auth' => [
                'user' => $request->user() ? [
                    'name' => $request->user()->name,
                    'avatarUrl' => $request->user()->gravatarUrl(),
                ] : null,
            ],
            'flash' => [
                'status' => $request->session()->get('status'),
                'recoveryCodes' => $request->session()->get('recoveryCodes'),
            ],
        ];
    }
}
