<?php

namespace App\Support;

/**
 * Three-way merge of a list of strings (a connection's highlight tokens, the
 * VRChat ids it holds), so saving from a page that loaded some time ago doesn't
 * undo what the server added since (the sync copies a VRChat name in as a
 * highlight token, another tab links a friend, ...).
 *
 * The client says what the list looked like when its form loaded (`base`) and
 * what the owner wants now (`submitted`). The owner's own edits are exactly the
 * difference between the two; anything in `current` that the page never saw is
 * kept. So: the submitted list, plus whatever is in `current` but not in `base`.
 * An entry the owner deleted is in `base` and not in `submitted`, so it stays
 * deleted; an entry they added is in `submitted`, so it is kept.
 *
 * Entries are compared ignoring case and surrounding space; the first spelling
 * wins and empties are dropped.
 */
final class ListMerge
{
    /**
     * @param  list<string>  $current  What is stored now.
     * @param  list<string>  $base  What the page loaded.
     * @param  list<string>  $submitted  What the owner wants.
     * @return list<string>
     */
    public static function threeWay(array $current, array $base, array $submitted): array
    {
        $baseKeys = array_flip(array_map(self::key(...), $base));
        $result = [];
        $seen = [];

        $add = function (string $entry) use (&$result, &$seen): void {
            $key = self::key($entry);

            if ($key !== '' && ! isset($seen[$key])) {
                $seen[$key] = true;
                $result[] = trim($entry);
            }
        };

        foreach ($submitted as $entry) {
            $add($entry);
        }

        foreach ($current as $entry) {
            if (! isset($baseKeys[self::key($entry)])) {
                $add($entry);
            }
        }

        return $result;
    }

    private static function key(string $entry): string
    {
        return mb_strtolower(trim($entry));
    }
}
