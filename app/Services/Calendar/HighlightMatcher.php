<?php

namespace App\Services\Calendar;

use App\Domain\Calendar\ManualTag;
use App\Domain\Calendar\ParsedEvent;
use App\Support\Regex;

/**
 * §5.0/§5.1's highlight matching. The only thing ever revealed to a viewer
 * is one of the owner's own configured highlight words/clauses — never a
 * raw event title. Matching strategy depends on what the feed actually
 * exposes:
 *
 * - full_detail: parse a "with X" / "w/ X" clause out of SUMMARY/
 *   DESCRIPTION and check whether X matches a configured word.
 * - free_busy_only: no real titles exist, so fall back to LOCATION (if
 *   present) or manual time-block tags — never fabricate a match against a
 *   generic "Busy" summary.
 * - mixed: decided per event by {@see ParsedEvent::$isFreeBusyOnly}.
 */
class HighlightMatcher
{
    /**
     * Built-in default clause pattern (regex fragment, no delimiters — same
     * convention as DND/nap event-name patterns). Owners can override this
     * via `users.highlight_clause_pattern` if their own titling convention
     * differs (e.g. "w:" instead of "w/"). The capture group is what gets
     * compared against the owner's configured highlight words.
     */
    private const DEFAULT_CLAUSE_PATTERN = '\b(?:with|w\/)\s+(.+?)(?:[,.;!?]|$)';

    /**
     * @param  string[]  $highlightWords  Owner's configured words, already decrypted.
     * @param  ManualTag[]  $manualTags  Already decrypted.
     */
    public function match(ParsedEvent $event, array $highlightWords, array $manualTags, ?string $clausePattern = null): ?string
    {
        if ($event->isFreeBusyOnly) {
            return $this->matchFreeBusyOnly($event, $highlightWords, $manualTags);
        }

        return $this->matchFullDetail($event, $highlightWords, $clausePattern)
            ?? $this->matchFreeBusyOnly($event, $highlightWords, $manualTags);
    }

    private function matchFullDetail(ParsedEvent $event, array $highlightWords, ?string $clausePattern): ?string
    {
        $pattern = $clausePattern ?: self::DEFAULT_CLAUSE_PATTERN;

        foreach ([$event->summary, $event->description] as $text) {
            if ($text === null) {
                continue;
            }

            // \x01 delimiter, same reasoning as ParsedEvent::matchesEventNamePattern:
            // lets an owner's pattern contain any printable character freely.
            // An invalid pattern fails closed (no match) rather than throwing —
            // a mistyped custom pattern shouldn't break every viewer's page.
            $matches = Regex::tryMatch("\x01".$pattern."\x01iu", $text);

            if ($matches === null || ! isset($matches[1])) {
                continue;
            }

            $candidate = trim($matches[1]);

            foreach ($highlightWords as $word) {
                if (mb_strtolower($candidate) === mb_strtolower($word)) {
                    return $word;
                }
            }
        }

        return null;
    }

    private function matchFreeBusyOnly(ParsedEvent $event, array $highlightWords, array $manualTags): ?string
    {
        if ($event->location !== null) {
            foreach ($highlightWords as $word) {
                if (mb_strtolower(trim($event->location)) === mb_strtolower($word)) {
                    return $word;
                }
            }
        }

        $weekday = (int) $event->start->format('w');
        $timeOfDay = $event->start->format('H:i');

        foreach ($manualTags as $tag) {
            if ($tag->matchesWeekdayAndTime($weekday, $timeOfDay)) {
                return $tag->word;
            }
        }

        return null;
    }
}
