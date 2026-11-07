<?php

namespace App\Rules;

use App\Services\Calendar\CalendarUrlGuard;
use App\Services\Calendar\UnsafeCalendarUrlException;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Early, user-facing SSRF rejection. The fetcher re-checks every request and redirect hop regardless. */
class SafeCalendarUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute must be a public http(s) URL.');

            return;
        }

        try {
            app(CalendarUrlGuard::class)->assertSafe($value);
        } catch (UnsafeCalendarUrlException) {
            $fail('The :attribute must be a public http(s) URL that resolves to a public address.');
        }
    }
}
