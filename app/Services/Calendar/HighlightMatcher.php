<?php

namespace App\Services\Calendar;

use App\Domain\Calendar\HighlightMatch;
use App\Domain\Calendar\ParsedEvent;
use App\Support\Regex;

/**
 * §5.0/§5.1's highlight matching, plus the "Host X"/"Visit X" convention.
 * Matching strategy depends on what the feed actually exposes:
 *
 * - full_detail: parse a "with X" / "w/ X" clause (or a "Host X"/"Visit X"
 *   title) out of SUMMARY/DESCRIPTION and check whether any token in X —
 *   split on DEFAULT_SPLIT_PATTERN, or the owner's own override — matches
 *   a configured word.
 * - free_busy_only: no real titles exist, so fall back to LOCATION (if
 *   present) — never fabricate a match against a generic "Busy" summary.
 * - mixed: decided per event by {@see ParsedEvent::$isFreeBusyOnly}.
 *
 * A matched event's title can still contain more than the bare highlight
 * word (e.g. "Dinner with Alice") — see App\Services\Calendar\
 * ActivityExtractor for the separate, independently-toggled extraction of
 * that "Dinner" freetext. This class only ever returns the word (plus
 * visiting/hosting), never surfaces other title text itself.
 */
class HighlightMatcher
{
    /**
     * Built-in default clause pattern (regex fragment, no delimiters — same
     * convention as DND/nap event-name patterns). Owners can override this
     * via `users.highlight_clause_pattern` if their own titling convention
     * differs (e.g. "w:" instead of "w/"). Captures everything after "with"/
     * "w/" to the end of the string — comma-separated tokens within that
     * are split out and checked individually, see matchTokens().
     *
     * Public (not private) so SettingsController can hand this same value
     * to the settings page as the documented default, instead of the UI
     * copy duplicating the pattern as its own literal that could drift
     * from what actually runs.
     */
    public const DEFAULT_CLAUSE_PATTERN = '\b(?:with|w\/)\s+(.+)$';

    /**
     * Owner-configurable delimiter (regex fragment, same bare-body
     * convention as every other pattern here) that splits a matched
     * clause's captured text into individual names before checking each
     * one — "with Alice, Bob" needs to become two tokens, "Alice" and
     * "Bob", not one "Alice, Bob" token that fails to contain either
     * configured word wholesale. A character class matching any one of
     * comma, ampersand, or slash — "Alice, Bob", "Alice & Bob", and
     * "Alice/Bob" all split the same way, every resulting token trimmed
     * afterward (see matchTokens()) so spacing around whichever character
     * was used never matters either. An owner using a different separator
     * entirely (e.g. semicolons) can override this to something like
     * `;\s*`.
     */
    public const DEFAULT_SPLIT_PATTERN = '[,&/]';

    private const HOST_PATTERN = '^host\s+(.+)$';

    private const VISIT_PATTERN = '^visit\s+(.+)$';

    /**
     * @param  string[]  $highlightWords  Owner's configured words, already decrypted.
     */
    public function match(ParsedEvent $event, array $highlightWords, ?string $clausePattern = null, ?string $splitPattern = null): ?HighlightMatch
    {
        if ($event->isFreeBusyOnly) {
            return $this->matchFreeBusyOnly($event, $highlightWords);
        }

        return $this->matchFullDetail($event, $highlightWords, $clausePattern, $splitPattern)
            ?? $this->matchFreeBusyOnly($event, $highlightWords);
    }

    private function matchFullDetail(ParsedEvent $event, array $highlightWords, ?string $clausePattern, ?string $splitPattern): ?HighlightMatch
    {
        foreach ([$event->summary, $event->description] as $text) {
            if ($text === null) {
                continue;
            }

            if ($match = $this->matchClauseText($text, $highlightWords, $clausePattern, $splitPattern)) {
                return $match;
            }
        }

        return null;
    }

    private function matchClauseText(string $text, array $highlightWords, ?string $clausePattern, ?string $splitPattern): ?HighlightMatch
    {
        $pattern = $clausePattern ?: self::DEFAULT_CLAUSE_PATTERN;

        // \x01 delimiter, same reasoning as ParsedEvent::matchesEventNamePattern:
        // lets an owner's pattern contain any printable character freely.
        // An invalid pattern fails closed (no match) rather than throwing —
        // a mistyped custom pattern shouldn't break every viewer's page.
        if (($matches = Regex::tryMatch("\x01".$pattern."\x01iu", $text)) !== null && isset($matches[1])) {
            if ($words = $this->matchTokens($matches[1], $highlightWords, $splitPattern)) {
                return new HighlightMatch($words);
            }
        }

        if (($matches = Regex::tryMatch("\x01".self::HOST_PATTERN."\x01iu", $text)) !== null) {
            if ($words = $this->matchTokens($matches[1], $highlightWords, $splitPattern)) {
                return new HighlightMatch($words, visiting: true);
            }
        }

        if (($matches = Regex::tryMatch("\x01".self::VISIT_PATTERN."\x01iu", $text)) !== null) {
            if ($words = $this->matchTokens($matches[1], $highlightWords, $splitPattern)) {
                return new HighlightMatch($words, hosting: true);
            }
        }

        return null;
    }

    /**
     * A clause can name more than one person ("with Alice, Bob") — split
     * on the owner's configured delimiter (falling back to
     * DEFAULT_SPLIT_PATTERN) and check each token, returning *every*
     * configured word that matches (not just the first), in the order
     * they're configured. Note this comparison is a case-sensitive
     * substring check (token *contains* word), not a case-insensitive
     * exact match — matching the source app's own (slightly inconsistent,
     * since the clause regex itself is case-insensitive) behavior
     * deliberately, rather than silently tightening it.
     *
     * @return string[]
     */
    private function matchTokens(string $tokenStr, array $highlightWords, ?string $splitPattern): array
    {
        $pattern = $splitPattern ?: self::DEFAULT_SPLIT_PATTERN;

        // An invalid delimiter pattern fails closed to "one token" (the
        // whole captured string, untouched) rather than losing the match
        // entirely — same fail-closed spirit as every other owner-supplied
        // pattern in this app.
        $rawTokens = Regex::trySplit("\x01".$pattern."\x01iu", $tokenStr) ?? [$tokenStr];
        $tokens = array_filter(array_map('trim', $rawTokens), fn ($t) => $t !== '');
        $matched = [];

        foreach ($highlightWords as $word) {
            foreach ($tokens as $token) {
                if (str_contains($token, $word)) {
                    $matched[] = $word;

                    break;
                }
            }
        }

        return $matched;
    }

    private function matchFreeBusyOnly(ParsedEvent $event, array $highlightWords): ?HighlightMatch
    {
        if ($event->location !== null) {
            foreach ($highlightWords as $word) {
                if (mb_strtolower(trim($event->location)) === mb_strtolower($word)) {
                    return new HighlightMatch([$word]);
                }
            }
        }

        return null;
    }
}
