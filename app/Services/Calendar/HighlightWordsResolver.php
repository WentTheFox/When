<?php

namespace App\Services\Calendar;

use App\Models\ConnectionAttributeValue;
use App\Models\ShareLink;
use App\Models\ShareLinkCache;
use App\Models\User;

/**
 * Single answer to "which highlight tokens apply to this share link?" — used
 * by the recompute job and the dashboard leaderboard so they can't drift.
 *
 * Tokens come ONLY from the identifier_list custom field the owner selected
 * (users.highlight_identifier_definition_id) on the connection tied to the
 * link. No field selected, no tied connection, or no value on it means no
 * tokens: nothing is highlighted for anyone. There is no per-link fallback.
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

    /**
     * Adds a name to a connection's highlight tokens (the owner's selected highlight
     * field). The name is copied in, not referenced: when someone renames themselves the
     * new name is added next to the old one, so events titled with an earlier name still
     * highlight. A name that differs from a token only in case is not added again, and
     * nothing here ever removes a token. No-op without a highlight field selected in
     * Settings or for a blank name.
     */
    public static function addToken(User $user, string $connectionId, string $name): void
    {
        $definitionId = $user->highlight_identifier_definition_id;
        $name = trim($name);

        if ($definitionId === null || $name === '') {
            return;
        }

        $row = ConnectionAttributeValue::firstOrNew(['connection_id' => $connectionId, 'attribute_definition_id' => $definitionId]);
        $tokens = self::normalize($row->identifier_list_value);

        foreach ($tokens as $token) {
            if (mb_strtolower($token) === mb_strtolower($name)) {
                return;
            }
        }

        $row->identifier_list_value = [...$tokens, $name];
        $row->save();

        // The computed share-link result embeds the highlight words.
        self::invalidateCaches([ShareLink::where('connection_id', $connectionId)->value('id')]);
    }

    /**
     * Computed results embed the words, so any change to their source must drop the cached result.
     *
     * @param  iterable<string|null>  $shareLinkIds
     */
    public static function invalidateCaches(iterable $shareLinkIds): void
    {
        $ids = collect($shareLinkIds)->filter()->unique()->values()->all();

        if ($ids !== []) {
            ShareLinkCache::whereIn('share_link_id', $ids)->delete();
        }
    }
}
