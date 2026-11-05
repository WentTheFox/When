<?php

namespace App\Services\Calendar;

use App\Models\ConnectionAttributeDefinition;
use App\Models\ConnectionAttributeValue;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Copies each link's own bypass_dnd flag (true and false alike) onto its tied
 * connection's boolean field, then selects that field as the owner's bypass
 * DND source. Same ground rules as HighlightWordsMigrator: non-destructive
 * (share_links.bypass_dnd stays as the fallback), fill-if-empty, re-runnable,
 * and links without a tied connection are skipped and keep their own flag.
 */
class BypassDndMigrator
{
    /** @return array{migrated: int, skipped_no_connection: int, skipped_existing: int} */
    public function migrate(User $user, ConnectionAttributeDefinition $definition): array
    {
        $counts = ['migrated' => 0, 'skipped_no_connection' => 0, 'skipped_existing' => 0];

        DB::transaction(function () use ($user, $definition, &$counts) {
            foreach ($user->shareLinks()->with('connection')->get() as $shareLink) {
                if ($shareLink->connection === null) {
                    $counts['skipped_no_connection']++;

                    continue;
                }

                $row = ConnectionAttributeValue::where('connection_id', $shareLink->connection->id)
                    ->where('attribute_definition_id', $definition->id)
                    ->lockForUpdate()
                    ->first();

                if ($row === null) {
                    ConnectionAttributeValue::create([
                        'connection_id' => $shareLink->connection->id,
                        'attribute_definition_id' => $definition->id,
                        'boolean_value' => (bool) $shareLink->bypass_dnd,
                    ]);
                } elseif ($row->boolean_value === null) {
                    $row->update(['boolean_value' => (bool) $shareLink->bypass_dnd]);
                } else {
                    $counts['skipped_existing']++;

                    continue;
                }

                $counts['migrated']++;
            }

            $user->update(['bypass_dnd_definition_id' => $definition->id]);
        });

        HighlightWordsResolver::invalidateCaches($user->shareLinks()->pluck('id'));

        return $counts;
    }
}
