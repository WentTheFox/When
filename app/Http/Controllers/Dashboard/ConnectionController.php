<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Models\ConnectionAttributeDefinition;
use App\Models\ConnectionAttributeValue;
use App\Models\ShareLink;
use App\Services\Calendar\HighlightWordsResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Full Connections CRM CRUD (Stage 7, §0.1). Every field this controller
 * touches that's suffixed _ciphertext is client-vault E2EE end to end —
 * the client encrypts before sending and decrypts after fetching; this
 * controller only ever moves ciphertext blobs it cannot open. See
 * vault.ts's createRecordKey for how the client derives each record's key
 * before calling store().
 *
 * The one exception is attribute values of an 'identifier'-type definition:
 * those are §0.2-tier — sent as plaintext identifier_value and encrypted at
 * rest with APP_KEY (ConnectionAttributeValue's 'encrypted' cast), so the
 * server can use them for external-service matching. 'identifier_list' is the
 * same idea with an array (identifier_list_value, APP_KEY-encrypted JSON); the
 * one the owner selects in Settings supplies share-link highlight tokens.
 */
class ConnectionController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Dashboard/Connections', [
            'connections' => $user->connections()->with(['attributeValues', 'sources', 'shareLink'])->get([
                'id', 'name_ciphertext', 'notes_ciphertext', 'archived',
            ])->map(fn (Connection $c) => $this->serialize($c)),
            'sources' => $user->connectionSources()->get(['id', 'category_id', 'name_ciphertext']),
            'categories' => $user->connectionSourceCategories()->get(['id', 'name_ciphertext', 'color_key']),
            'attributeDefinitions' => $user->connectionAttributeDefinitions()->get(['id', 'label_ciphertext', 'type', 'options_ciphertext']),
            // Which fields currently drive share-link highlighting / DND bypass (chosen in Settings), so deleting one can warn.
            'fieldRoles' => [
                'highlight' => $user->highlight_identifier_definition_id,
                'bypass' => $user->bypass_dnd_definition_id,
            ],
            'edges' => $user->connectionEdges()->get(['id', 'from_connection_id', 'to_connection_id']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateConnection($request, requireId: true);

        $connection = DB::transaction(function () use ($request, $data) {
            $connection = $request->user()->connections()->create([
                'id' => $data['id'],
                'name_ciphertext' => $data['name_ciphertext'],
                'notes_ciphertext' => $data['notes_ciphertext'] ?? null,
                'archived' => $data['archived'] ?? false,
            ]);

            if (array_key_exists('source_ids', $data)) {
                $connection->sources()->sync($data['source_ids']);
            }

            $this->syncAttributeValues($connection, $data['attribute_values'] ?? null);

            return $connection;
        });

        $connection->refresh();

        return response()->json($this->serialize($connection), 201);
    }

    public function update(Request $request, string $connection): JsonResponse
    {
        $connection = $this->findOwned($request, $connection);
        $data = $this->validateConnection($request, requireId: false);

        $highlightBefore = $this->highlightSnapshot($request, $connection);

        DB::transaction(function () use ($connection, $data) {
            $connection->fill(array_filter([
                'name_ciphertext' => $data['name_ciphertext'] ?? null,
                'notes_ciphertext' => $data['notes_ciphertext'] ?? null,
                'archived' => $data['archived'] ?? null,
            ], fn ($value) => $value !== null))->save();

            // Archiving a connection archives its share link too (it only hides it from the stats).
            if (array_key_exists('archived', $data) && $data['archived'] !== null) {
                $connection->shareLink()->update(['archived' => $data['archived']]);
            }

            if (array_key_exists('source_ids', $data)) {
                $connection->sources()->sync($data['source_ids']);
            }

            if (array_key_exists('attribute_values', $data)) {
                $this->syncAttributeValues($connection, $data['attribute_values']);
            }
        });

        $connection->refresh();
        $this->invalidateIfHighlightsChanged($request, $connection, $highlightBefore);

        return response()->json($this->serialize($connection));
    }

    /**
     * What this connection currently contributes to share-link highlighting:
     * its own link's id plus its list under the owner's chosen field.
     * Null when no field is chosen (the legacy words are unaffected by
     * connection edits, so there's nothing to track).
     *
     * @return array{share_link_id: ?string, words: ?list<string>, bypass_dnd: ?bool}|null
     */
    private function highlightSnapshot(Request $request, Connection $connection): ?array
    {
        $user = $request->user();
        $wordsId = $user->highlight_identifier_definition_id;
        $bypassId = $user->bypass_dnd_definition_id;

        if ($wordsId === null && $bypassId === null) {
            return null;
        }

        $rows = ConnectionAttributeValue::where('connection_id', $connection->id)
            ->whereIn('attribute_definition_id', array_filter([$wordsId, $bypassId]))
            ->get()
            ->keyBy('attribute_definition_id');

        return [
            'share_link_id' => $connection->shareLink?->id,
            'words' => $wordsId === null || ! $rows->has($wordsId)
                ? null
                : HighlightWordsResolver::normalize($rows[$wordsId]->identifier_list_value),
            'bypass_dnd' => $bypassId === null || ! $rows->has($bypassId) ? null : $rows[$bypassId]->boolean_value,
        ];
    }

    /** Computed share-link results embed the words and the bypass-DND flag, so drop the cached result of every link whose inputs just changed */
    private function invalidateIfHighlightsChanged(Request $request, Connection $connection, ?array $before): void
    {
        $after = $this->highlightSnapshot($request, $connection);

        if ($before === null || $after === null || $before === $after) {
            return;
        }

        HighlightWordsResolver::invalidateCaches([$after['share_link_id']]);
    }

    /**
     * Every create/update returns the full row (not just {status: ok}) so
     * the Vue page can splice it straight into its reactive list without a
     * full reload — see ConnectionCard.vue's props.
     */
    private function serialize(Connection $connection): array
    {
        return [
            'id' => $connection->id,
            'source_ids' => $connection->sources->pluck('id'),
            'name_ciphertext' => $connection->name_ciphertext,
            'notes_ciphertext' => $connection->notes_ciphertext,
            'share_link' => $this->serializeShareLink($connection->shareLink),
            'archived' => $connection->archived,
            // identifier_value is the server-decrypted (§0.2) field; see ConnectionAttributeValue.
            'attribute_values' => $connection->attributeValues()->get(['attribute_definition_id', 'value_ciphertext', 'identifier_value', 'identifier_list_value', 'boolean_value']),
        ];
    }

    /** @return array{id: string, highlight_token: ?string, archived: bool}|null */
    private function serializeShareLink(?ShareLink $shareLink): ?array
    {
        return $shareLink === null ? null : [
            'id' => $shareLink->id,
            'highlight_token' => $shareLink->highlight_token,
            'archived' => $shareLink->archived,
        ];
    }

    public function destroy(Request $request, string $connection): JsonResponse
    {
        // forceDelete(), not delete() — see ActivityLocalizationController::destroy's
        // comment: SoftDeletes on this model exists only for account-wide
        // deletion, not this single-record user action. Also matters here
        // specifically: connection_attribute_values/connection_source_links'
        // cascadeOnDelete() FKs only fire on a real DELETE.
        $connection = $this->findOwned($request, $connection);
        // The share link (share_links.connection_id, cascadeOnDelete) goes with it.
        $connection->forceDelete();

        return response()->json(['status' => 'ok']);
    }

    /**
     * source_ids/attribute_definition_id both scope their
     * `exists` check to the requesting user's own rows — an unscoped
     * `exists:table,id` only proves the id exists *somewhere*, not that it
     * belongs to this owner, which would otherwise let one user attach
     * their own connection to another user's source or
     * attribute definition (a real cross-user IDOR, not just a missing
     * test — see ConnectionsIsolationTest).
     */
    private function validateConnection(Request $request, bool $requireId): array
    {
        $userId = $request->user()->id;

        $data = $request->validate([
            'id' => $requireId ? ['required', 'uuid', 'unique:connections,id'] : ['sometimes'],
            'source_ids' => ['nullable', 'array'],
            'source_ids.*' => ['uuid', Rule::exists('connection_sources', 'id')->where('user_id', $userId)],
            'name_ciphertext' => $requireId ? ['required', 'string'] : ['sometimes', 'string'],
            'notes_ciphertext' => ['nullable', 'string'],
            'archived' => ['nullable', 'boolean'],
            'attribute_values' => ['nullable', 'array'],
            'attribute_values.*.attribute_definition_id' => ['required', 'uuid', Rule::exists('connection_attribute_definitions', 'id')->where('user_id', $userId)],
            'attribute_values.*.value_ciphertext' => ['nullable', 'string'],
            'attribute_values.*.identifier_value' => ['nullable', 'string', 'max:1000'],
            'attribute_values.*.boolean_value' => ['nullable', 'boolean'],
            'attribute_values.*.identifier_list_value' => ['nullable', 'array', 'max:500'],
            'attribute_values.*.identifier_list_value.*' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->assertValueShapesMatchDefinitions($data['attribute_values'] ?? [], $userId);

        return $data;
    }

    /**
     * Each definition type takes exactly one value column: 'identifier' →
     * identifier_value (plaintext string, §0.2), 'identifier_list' →
     * identifier_list_value (plaintext array, §0.2), 'boolean' →
     * boolean_value (§0.2, APP_KEY-encrypted yes/no), everything else →
     * value_ciphertext (E2EE, §0.1). Never two, never the wrong one, so a
     * client bug can't leak E2EE-intended data into a server-readable column
     * or vice versa.
     */
    private function assertValueShapesMatchDefinitions(array $attributeValues, string $userId): void
    {
        if ($attributeValues === []) {
            return;
        }

        $types = ConnectionAttributeDefinition::where('user_id', $userId)
            ->whereIn('id', array_column($attributeValues, 'attribute_definition_id'))
            ->pluck('type', 'id');

        $columns = ['value_ciphertext', 'identifier_value', 'identifier_list_value', 'boolean_value'];

        foreach ($attributeValues as $i => $value) {
            $field = match ($types[$value['attribute_definition_id']] ?? null) {
                'identifier' => 'identifier_value',
                'identifier_list' => 'identifier_list_value',
                'boolean' => 'boolean_value',
                default => 'value_ciphertext',
            };

            $others = array_filter($columns, fn ($c) => $c !== $field && ($value[$c] ?? null) !== null);

            if (($value[$field] ?? null) === null || $others !== []) {
                throw ValidationException::withMessages(["attribute_values.$i.$field" => "Expected $field only for this attribute type."]);
            }
        }
    }

    private function syncAttributeValues(Connection $connection, ?array $attributeValues): void
    {
        if ($attributeValues === null) {
            return;
        }

        $connection->attributeValues()->delete();

        foreach ($attributeValues as $value) {
            ConnectionAttributeValue::create([
                'connection_id' => $connection->id,
                'attribute_definition_id' => $value['attribute_definition_id'],
                'value_ciphertext' => $value['value_ciphertext'] ?? null,
                'identifier_value' => $value['identifier_value'] ?? null,
                'boolean_value' => $value['boolean_value'] ?? null,
                'identifier_list_value' => isset($value['identifier_list_value']) ? HighlightWordsResolver::normalize($value['identifier_list_value']) : null,
            ]);
        }
    }

    private function findOwned(Request $request, string $id): Connection
    {
        return $request->user()->connections()->where('id', $id)->firstOrFail();
    }
}
