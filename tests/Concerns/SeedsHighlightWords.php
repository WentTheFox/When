<?php

namespace Tests\Concerns;

use App\Models\Connection;
use App\Models\ConnectionAttributeDefinition;
use App\Models\ConnectionAttributeValue;
use App\Models\ShareLink;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Highlight tokens now live in an identifier_list field on the link's tied
 * connection (see HighlightWordsResolver); this seeds that whole chain.
 */
trait SeedsHighlightWords
{
    /** Selects (creating if needed) the owner's highlight field and gives the link's connection (creating + tying one if needed) the words. */
    protected function seedHighlightWords(User $user, ShareLink $link, array $words): Connection
    {
        $definition = $user->highlight_identifier_definition_id !== null
            ? ConnectionAttributeDefinition::find($user->highlight_identifier_definition_id)
            : ConnectionAttributeDefinition::create([
                'id' => (string) Str::uuid(), 'user_id' => $user->id, 'label_ciphertext' => 'opaque', 'type' => 'identifier_list',
            ]);
        $user->update(['highlight_identifier_definition_id' => $definition->id]);

        $connection = Connection::where('share_link_id', $link->id)->first() ?? Connection::create([
            'id' => (string) Str::uuid(), 'user_id' => $user->id, 'name_ciphertext' => 'opaque', 'share_link_id' => $link->id,
        ]);

        ConnectionAttributeValue::updateOrCreate(
            ['connection_id' => $connection->id, 'attribute_definition_id' => $definition->id],
            ['identifier_list_value' => $words],
        );

        return $connection;
    }
}
