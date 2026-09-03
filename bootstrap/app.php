<?php

use App\Http\Middleware\AddNoIndexHeader;
use App\Http\Middleware\HandleInertiaRequests;
use App\Support\Locales;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);
        $middleware->append(AddNoIndexHeader::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // `php artisan down` throws a plain 503 HttpException from
        // PreventRequestsDuringMaintenance — well before the 'web'
        // middleware group (HandleInertiaRequests, StartSession) ever
        // runs, so none of the usual shared Inertia props (auth,
        // colorPalette, ...) exist here. Render a self-contained page with
        // only what it needs, passed explicitly, using its own root Blade
        // view (maintenance.blade.php) since app.blade.php's csrf_token()
        // call would throw without a started session. Any other
        // HttpException subclass (404, 419, throttling, ...) also has
        // status !== 503 and falls through to Laravel's default handling
        // via the `return null`.
        $exceptions->render(function (HttpException $e, Request $request) {
            if ($e->getStatusCode() !== 503 || $request->expectsJson()) {
                return null;
            }

            $locale = Locales::DEFAULT;
            $firstSegment = $request->segment(1);
            if ($firstSegment !== null && Locales::isValid($firstSegment)) {
                $locale = $firstSegment;
            }

            return Inertia::render('Errors/Maintenance', [
                'appName' => config('app.name'),
                'locale' => $locale,
                'textDirection' => in_array($locale, Locales::RTL, true) ? 'rtl' : 'ltr',
                'locales' => Locales::forFrontend(),
            ])
                ->rootView('maintenance')
                ->toResponse($request)
                ->setStatusCode(503);
        });
    })->create();
