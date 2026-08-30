<?php

namespace App\Support;

/**
 * Owner-supplied regex (DND/nap event-name patterns, custom highlight
 * clause pattern — §5.1) must never be able to break availability
 * computation for every viewer just because it's malformed. `@preg_match`
 * alone isn't enough here: PHPUnit's error handler ignores the `@`
 * suppression operator when converting warnings to test failures, so an
 * invalid pattern in a caller under test still surfaces as a warning even
 * though the application-level behavior (fail closed, no match) is
 * correct. This swallows the warning at the source instead.
 */
class Regex
{
    /** Returns the match groups on success, or null if the pattern is invalid or didn't match. */
    public static function tryMatch(string $pattern, string $subject): ?array
    {
        set_error_handler(static fn () => true);

        try {
            $result = @preg_match($pattern, $subject, $matches);
        } finally {
            restore_error_handler();
        }

        return $result === 1 ? $matches : null;
    }
}
