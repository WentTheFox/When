<?php

namespace App\Services\Calendar;

use App\Models\ConnectionAttributeValue;
use App\Models\ShareLink;
use App\Models\ShareLinkCache;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;

/**
 * Single answer to "which highlight tokens apply to this share link?" — used
 * by the recompute job and the dashboard leaderboard so they can't drift.
 *
 * Tokens come ONLY from the identifier_list custom field the owner selected
 * (users.highlight_identifier_definition_id) on the connection tied to the
 * link. No field selected, no tied connection, or no value on it means no
 * tokens: nothing is highlighted for anyone. The legacy share_link_words rows
 * are no longer consulted at runtime; they remain only as the source that
 * {@see HighlightWordsMigrator} copies from.
 *
 * Both sources are §0.2 tier (APP_KEY-encrypted at rest), so decrypting here
 * is within the documented model; callers must still never log the result.
 */
class HighlightWordsResolver
{
    /** @return list<string> */
    public function forShareLink(ShareLink $shareLink, User $user): array
    {
        return $this->resolve($shareLink, $user)['words'];
    }

    /** @return array{words: list<string>, source: 'connection'|'none'} */
    public function resolve(ShareLink $shareLink, User $user): array
    {
        $fromConnection = $this->fromConnection($shareLink, $user);

        return $fromConnection !== null
            ? ['words' => $fromConnection, 'source' => 'connection']
            : ['words' => [], 'source' => 'none'];
    }

    /** Pre-field per-link words — migration source only, never used to compute availability. @return list<string> */
    public function legacyWords(ShareLink $shareLink): array
    {
        return $shareLink->words()
            ->pluck('word_ciphertext')
            ->map(fn (string $ciphertext) => Crypt::decryptString($ciphertext))
            ->all();
    }

    /** @return list<string>|null Null = nothing applicable, caller should fall back. */
    private function fromConnection(ShareLink $shareLink, User $user): ?array
    {
        $definitionId = $user->highlight_identifier_definition_id;
        $connection = $definitionId !== null ? $shareLink->connection : null;

        if ($connection === null) {
            return null;
        }

        $row = ConnectionAttributeValue::where('connection_id', $connection->id)
            ->where('attribute_definition_id', $definitionId)
            ->first();

        return $row === null ? null : static::normalize($row->identifier_list_value);
    }

    /**
     * @param  mixed  $list
     * @return list<string>
     */
    public static function normalize($list): array
    {
        if (! is_array($list)) {
            return [];
        }

        $clean = [];
        foreach ($list as $item) {
            if (is_string($item) && trim($item) !== '') {
                $clean[] = trim($item);
            }
        }

        return array_values(array_unique($clean));
    }

    /** Computed results embed the words, so any change to their source must drop the cached result. */
    public static function invalidateCaches(iterable $shareLinkIds): void
    {
        $ids = collect($shareLinkIds)->filter()->unique()->values()->all();

        if ($ids !== []) {
            ShareLinkCache::whereIn('share_link_id', $ids)->delete();
        }
    }
}
