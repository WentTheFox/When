<?php

namespace App\Http\Middleware;

use App\Support\ReadOnlyMode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * While the deploy window's read-only flag is set, every request that could write is refused with
 * 503 + Retry-After before any handler runs, which is what makes the client's automatic retry
 * (resources/js/readOnlyRetry.ts) safe even for writes.
 */
class BlockWritesInReadOnlyMode
{
    /**
     * GET routes that write to the database. None today; add any such route here.
     */
    protected const WRITING_GET_PATTERNS = [];

    /**
     * Non-GET routes that only read, so they keep working in the window.
     */
    protected const READING_POST_PATTERNS = [
        'login/lookup',
        'settings/calendar/preview',
    ];

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! ReadOnlyMode::isActive() || ! $this->isWrite($request)) {
            return $next($request);
        }

        // Rendered by bootstrap/app.php's 503 handler (same page as `artisan down`), which keeps the headers.
        throw new HttpException(
            503,
            'When? is temporarily read-only while an update is being applied.',
            null,
            ['Retry-After' => (string) ReadOnlyMode::RETRY_AFTER_SECONDS],
        );
    }

    private function isWrite(Request $request): bool
    {
        if ($request->is(...self::READING_POST_PATTERNS)) {
            return false;
        }

        if (! $request->isMethodSafe()) {
            return true;
        }

        return self::WRITING_GET_PATTERNS !== [] && $request->is(...self::WRITING_GET_PATTERNS);
    }
}
