<?php

namespace App\Services\Calendar;

use App\Models\ConnectionAttributeDefinition;
use App\Models\ConnectionAttributeValue;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * One-time (re-runnable) move of the legacy per-share-link highlight words
 * onto the tied connection's identifier_list value, followed by pointing the
 * owner's setting at that field.
 *
 * Non-destructive on purpose: share_link_words rows are left alone. They stay
 * the fallback for links that can't migrate (no tied connection) and give the
 * owner a way back by clearing the setting.
 *
 * Fill-if-empty, like the identifier bulk copy: a connection that already has
 * a non-empty list is never overwritten or merged into, so re-running (or
 * two tabs racing) can't clobber anything.
 */
class HighlightWordsMigrator
{
    public function __construct(private HighlightWordsResolver $resolver) {}

    /** @return array{migrated: int, skipped_no_connection: int, skipped_existing: int, skipped_empty: int} */
    public function migrate(User $user, ConnectionAttributeDefinition $definition): array
    {
        $counts = ['migrated' => 0, 'skipped_no_connection' => 0, 'skipped_existing' => 0, 'skipped_empty' => 0];

        DB::transaction(function () use ($user, $definition, &$counts) {
            foreach ($user->shareLinks()->with('connection')->get() as $shareLink) {
                $words = HighlightWordsResolver::normalize($this->resolver->legacyWords($shareLink));

                if ($words === []) {
                    $counts['skipped_empty']++;

                    continue;
                }

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
                        'identifier_list_value' => $words,
                    ]);
                } elseif (HighlightWordsResolver::normalize($row->identifier_list_value) === []) {
                    $row->update(['identifier_list_value' => $words]);
                } else {
                    $counts['skipped_existing']++;

                    continue;
                }

                $counts['migrated']++;
            }

            $user->update(['highlight_identifier_definition_id' => $definition->id]);
        });

        HighlightWordsResolver::invalidateCaches($user->shareLinks()->pluck('id'));

        return $counts;
    }
}
