<?php

namespace App\Services\Calendar;

use App\Models\ConnectionAttributeValue;
use App\Models\ShareLink;
use App\Models\User;

/**
 * Whether a share link bypasses do-not-disturb. Decided ONLY by the boolean
 * custom field the owner selected (users.bypass_dnd_definition_id) on the
 * connection tied to the link. No field selected, no tied connection, or no
 * value set means no bypass: nobody bypasses DND. share_links.bypass_dnd is
 * no longer consulted at runtime; it remains only as the source that
 * {@see BypassDndMigrator} copies from.
 */
class BypassDndResolver
{
    /** @return array{value: bool, source: 'connection'|'none'} */
    public function resolve(ShareLink $shareLink, User $user): array
    {
        $definitionId = $user->bypass_dnd_definition_id;
        $connection = $definitionId !== null ? $shareLink->connection : null;

        if ($connection !== null) {
            $row = ConnectionAttributeValue::where('connection_id', $connection->id)
                ->where('attribute_definition_id', $definitionId)
                ->first();

            if ($row !== null && $row->boolean_value !== null) {
                return ['value' => $row->boolean_value, 'source' => 'connection'];
            }
        }

        return ['value' => false, 'source' => 'none'];
    }

    public function forShareLink(ShareLink $shareLink, User $user): bool
    {
        return $this->resolve($shareLink, $user)['value'];
    }
}
