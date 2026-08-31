<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Belt and braces alongside public/robots.txt's blanket Disallow and
 * app.blade.php's <meta name="robots">: this app has no public content
 * worth indexing (every page is a private dashboard or a share link meant
 * for one named recipient), and unlike the meta tag this also covers
 * non-HTML responses (the JSON API endpoints under /api) that never render
 * app.blade.php at all.
 */
class AddNoIndexHeader
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');

        return $response;
    }
}
