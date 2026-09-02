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

    /**
     * Same fail-closed discipline as tryMatch, for preg_split — used by
     * HighlightMatcher::matchTokens to split a "with X, Y, Z" clause into
     * individual names on an owner-configurable delimiter pattern
     * (users.highlight_split_pattern) instead of a hardcoded literal
     * comma. An invalid split pattern fails closed to "the whole string
     * is one token" (never splits) rather than losing the match entirely.
     *
     * @return string[]|null
     */
    public static function trySplit(string $pattern, string $subject): ?array
    {
        set_error_handler(static fn () => true);

        try {
            $result = @preg_split($pattern, $subject);
        } finally {
            restore_error_handler();
        }

        return $result === false ? null : $result;
    }

    /**
     * Counts capturing groups in a regex *body* (no delimiters — same
     * bare-fragment convention as every owner-supplied pattern in this
     * app). Used to validate that a highlight/activity clause pattern
     * captures exactly the one group its caller actually reads
     * (HighlightMatcher::matchClauseText / ActivityExtractor::extract
     * both read `$matches[1]` specifically) — 0 groups means the field
     * would silently never do anything, and 2+ means every group past
     * the first is silently ignored, both easy mistakes to make by hand
     * and not something the app should fail closed on quietly.
     *
     * Deliberately doesn't hand-parse the pattern text to tell a real
     * capturing group apart from `(?:…)`/`(?=…)`/`(?!…)`/`(?<=…)`/`(?<!…)`
     * (or a named group written as `(?<name>…)`/`(?P<name>…)`/`(?'name'…)`,
     * which also start with `(?` despite being capturing) — that's a
     * genuinely hard problem once escapes and character classes are in
     * play. Instead it lets PCRE itself count them: wrapping the pattern
     * in `(?:…)` ORed with an empty alternative guarantees the overall
     * match always succeeds (the real pattern, or nothing) without adding
     * a capturing group of its own, and PREG_UNMATCHED_AS_NULL then fills
     * $matches with exactly one slot per real capturing group in the
     * original pattern (null for one that didn't participate), whichever
     * alternation branch actually matched.
     *
     * @return int|null Capture group count, or null if the pattern itself doesn't compile.
     */
    public static function countCaptureGroups(string $pattern): ?int
    {
        set_error_handler(static fn () => true);

        try {
            $result = @preg_match("\x01(?:{$pattern})|\x01iu", '', $matches, PREG_UNMATCHED_AS_NULL);
        } finally {
            restore_error_handler();
        }

        if ($result === false) {
            return null;
        }

        // A *named* group (`(?<name>…)`) shows up in $matches under BOTH
        // its numeric key and its name — e.g. group 1 named "foo" appears
        // as both [1] and ['foo'] — so a plain count($matches) silently
        // double-counts every named group. Only the integer keys are the
        // actual 0..N capture-group numbering PCRE assigns; group 0 (the
        // whole match) is excluded from the count itself.
        $numericKeys = array_filter(array_keys($matches), 'is_int');

        return count($numericKeys) - 1;
    }

    /**
     * Laravel inline-closure-rule signature (`[Attribute, mixed, Closure]`)
     * — pass this directly in a `validate()` rules array, e.g.
     * `'highlight_clause_pattern' => ['nullable', 'string', Regex::validateSingleCaptureGroup(...)]`.
     * Only fields whose caller reads a specific capture group by number
     * need this (HighlightMatcher::matchClauseText / ActivityExtractor::
     * extract both read `$matches[1]`) — DND/nap/work-event-name patterns
     * are a plain boolean match, the tentative/open-end/open-start title
     * patterns only ever strip group 0 (the whole match), and
     * highlight_split_pattern isn't matched against a title at all (it's
     * a preg_split delimiter, see HighlightMatcher::matchTokens) — none
     * of those six call this. An empty value is left to `nullable`
     * (every field this applies to allows blank = off/default) — this
     * rule only fires once there's actually a pattern to check.
     */
    public static function validateSingleCaptureGroup(string $attribute, mixed $value, \Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $count = self::countCaptureGroups($value);

        if ($count === null) {
            $fail('The :attribute is not a valid regular expression.');

            return;
        }

        if ($count !== 1) {
            $fail($count === 0
                ? 'The :attribute must have exactly one capture group — wrap the part you want to use in parentheses, e.g. (…). Use (?:…) for any other grouping so it stays uncounted.'
                : 'The :attribute must have exactly one capture group, found '.$count.'. Change any extra ones to a non-capturing group (?:…) instead of (…).');
        }
    }
}
