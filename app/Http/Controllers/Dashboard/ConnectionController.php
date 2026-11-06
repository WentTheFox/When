<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Models\ConnectionAttributeDefinition;
use App\Models\ConnectionAttributeValue;
use App\Models\ShareLink;
use App\Services\Calendar\HighlightWordsResolver;
use App\Services\Discord\DiscordSyncService;
use App\Services\Vrcx\VrcxSyncService;
use App\Support\FieldFeatures;
use App\Support\ListMerge;
use App\Support\SortOrder;
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
    public function __construct(private readonly VrcxSyncService $vrcx, private readonly DiscordSyncService $discord) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        // Stale VRChat data is refreshed in the background; this load shows what is cached.
        $this->vrcx->maybeQueue($user);

        return Inertia::render('Dashboard/Connections', [
            'connections' => $user->connections()->with(['attributeValues', 'sources', 'shareLink'])->ordered()->get([
                'id', 'name_ciphertext', 'notes_ciphertext', 'archived',
            ])->map(fn (Connection $c) => $this->serialize($c)),
            'sources' => $user->connectionSources()->ordered()->get(['id', 'category_id', 'name_ciphertext']),
            'categories' => $user->connectionSourceCategories()->ordered()->get(['id', 'name_ciphertext', 'color_key']),
            'attributeDefinitions' => $user->connectionAttributeDefinitions()->ordered()->get(['id', 'label_ciphertext', 'type', 'options_ciphertext', 'icon_rules_ciphertext']),
            // Fields that site features depend on (field id => feature labels): those can't be deleted.
            'fieldFeatures' => (object) FieldFeatures::forUser($user),
            'edges' => $user->connectionEdges()->get(['id', 'from_connection_id', 'to_connection_id']),
            // §0.2 tier (server-readable): cached VRChat names/pictures and time together.
            'vrchat' => $this->vrcx->summary($user),
            'discord' => $this->discord->summary($user),
            // The multi-line custom field that holds what used to be the built-in notes; null until there is one.
            'notesFieldId' => $user->notes_field_definition_id,
        ]);
    }

    /**
     * Notes are an ordinary multi-line custom field now, not a built-in. The browser calls this to
     *  - create that field (once per owner; the first call says how: its id, its encrypted label and a
     *    default icon rule, all built client-side because only the browser can encrypt them),
     *  - move every connection's old note into it, and
     *  - save new values for it (merged notes, VRChat notes carried over) while clearing the old note.
     *
     * The old note and a field value are encrypted with the same key (the connection's own), so moving one
     * is just moving the ciphertext; the server never reads either. Where a connection already has a value
     * in the field the two cannot be merged here, so its id comes back as a conflict and the browser merges
     * them and calls again with `values`. Safe to call repeatedly, and from two tabs at once.
     */
    public function notesField(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'id' => ['nullable', 'uuid'],
            'label_ciphertext' => ['nullable', 'string'],
            'icon_rules_ciphertext' => ['nullable', 'string'],
            'values' => ['nullable', 'array', 'max:500'],
            'values.*.connection_id' => ['required', 'uuid', Rule::exists('connections', 'id')->where('user_id', $user->id)],
            'values.*.value_ciphertext' => ['required', 'string'],
        ]);

        [$definition, $created, $changed, $conflicts] = DB::transaction(function () use ($user, $data) {
            // The same row lock the other field-binding writes take: two tabs can't both create the field.
            $owner = $user->newQuery()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $definition = $owner->notes_field_definition_id === null
                ? null
                : ConnectionAttributeDefinition::where('user_id', $owner->id)->where('id', $owner->notes_field_definition_id)->first();
            $created = false;

            if ($definition === null) {
                if (empty($data['id']) || empty($data['label_ciphertext'])) {
                    throw ValidationException::withMessages(['id' => 'There is no Notes field yet; say how to create it (id and label_ciphertext).']);
                }

                if (ConnectionAttributeDefinition::withTrashed()->where('id', $data['id'])->exists()) {
                    throw ValidationException::withMessages(['id' => 'That id is already taken.']);
                }

                $definition = $owner->connectionAttributeDefinitions()->create([
                    'id' => $data['id'],
                    'label_ciphertext' => $data['label_ciphertext'],
                    'type' => 'textarea',
                    'icon_rules_ciphertext' => $data['icon_rules_ciphertext'] ?? null,
                    'sort_order' => SortOrder::next($owner->connectionAttributeDefinitions()),
                ]);
                $owner->forceFill(['notes_field_definition_id' => $definition->id])->save();
                $created = true;
            }

            $changed = [];
            $conflicts = [];
            // Keyed by connection, so a repeated id is saved once (the last one wins).
            $given = [];
            foreach ($data['values'] ?? [] as $item) {
                $given[$item['connection_id']] = $item;
            }

            // Values the browser merged itself: they replace whatever is there and retire the old note.
            foreach ($given as $connectionId => $item) {
                ConnectionAttributeValue::updateOrCreate(
                    ['connection_id' => $connectionId, 'attribute_definition_id' => $definition->id],
                    ['value_ciphertext' => $item['value_ciphertext'], 'identifier_value' => null, 'identifier_list_value' => null, 'boolean_value' => null],
                );
                $owner->connections()->where('id', $connectionId)->update(['notes_ciphertext' => null]);
                $changed[] = $connectionId;
            }

            // Every other old note moves as it is, unless the connection already has a value in the field.
            $owner->connections()->whereNotNull('notes_ciphertext')->whereNotIn('id', array_keys($given))->lockForUpdate()->get()
                ->each(function (Connection $connection) use ($definition, &$changed, &$conflicts) {
                    $taken = ConnectionAttributeValue::where('connection_id', $connection->id)
                        ->where('attribute_definition_id', $definition->id)
                        ->whereNotNull('value_ciphertext')
                        ->exists();

                    if ($taken) {
                        $conflicts[] = $connection->id;

                        return;
                    }

                    ConnectionAttributeValue::updateOrCreate(
                        ['connection_id' => $connection->id, 'attribute_definition_id' => $definition->id],
                        ['value_ciphertext' => $connection->notes_ciphertext, 'identifier_value' => null, 'identifier_list_value' => null, 'boolean_value' => null],
                    );
                    $connection->forceFill(['notes_ciphertext' => null])->save();
                    $changed[] = $connection->id;
                });

            return [$definition, $created, $changed, $conflicts];
        });

        return response()->json([
            'definition' => $definition->only(['id', 'label_ciphertext', 'type', 'options_ciphertext', 'icon_rules_ciphertext']),
            'created' => $created,
            'connections' => $user->connections()->with(['attributeValues', 'sources', 'shareLink'])->whereIn('id', $changed)->get()
                ->map(fn (Connection $c) => $this->serialize($c))->values(),
            'conflicts' => $conflicts,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateConnection($request, requireId: true);

        $connection = DB::transaction(function () use ($request, $data) {
            $connection = $request->user()->connections()->create([
                'sort_order' => SortOrder::next($request->user()->connections()),
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

        // A VRChat id entered on this save is looked up now, outside the transaction (it waits on VRCX).
        $this->vrcx->syncConnection($request->user(), $connection);

        $connection->refresh();

        return response()->json($this->serialize($connection) + ['vrchat_profiles' => $this->vrcx->profilesFor($connection->id)], 201);
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

            if (array_key_exists('source_ids', $data)) {
                $connection->sources()->sync($data['source_ids']);
            }

            if (array_key_exists('attribute_values', $data)) {
                $this->syncAttributeValues($connection, $data['attribute_values']);
            }
        });

        if (array_key_exists('attribute_values', $data)) {
            $this->vrcx->syncConnection($request->user(), $connection);
        }

        $connection->refresh();
        $this->invalidateIfHighlightsChanged($request, $connection, $highlightBefore);

        return response()->json($this->serialize($connection) + ['vrchat_profiles' => $this->vrcx->profilesFor($connection->id)]);
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

    /**
     * Computed share-link results embed the words and the bypass-DND flag, so drop the cached result of every link whose inputs just changed
     *
     * @param  array{share_link_id: ?string, words: ?list<string>, bypass_dnd: ?bool}|null  $before
     */
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
     *
     * @return array<string, mixed>
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

    /** @return array{id: string, highlight_token: ?string, last_visited_at: ?string}|null */
    private function serializeShareLink(?ShareLink $shareLink): ?array
    {
        return $shareLink === null ? null : [
            'id' => $shareLink->id,
            'highlight_token' => $shareLink->highlight_token,
            'last_visited_at' => $shareLink->last_visited_at?->toIso8601String(),
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
     *
     * @return array<string, mixed>
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
            // What the client's copy of that list looked like when it loaded; see ListMerge. Optional: without it the list is replaced.
            'attribute_values.*.identifier_list_base' => ['nullable', 'array', 'max:500'],
            'attribute_values.*.identifier_list_base.*' => ['nullable', 'string', 'max:1000'],
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
     *
     * @param  array<int, array<string, mixed>>  $attributeValues
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

    /** @param  array<int, array<string, mixed>>|null  $attributeValues */
    private function syncAttributeValues(Connection $connection, ?array $attributeValues): void
    {
        if ($attributeValues === null) {
            return;
        }

        $existing = $connection->attributeValues()->lockForUpdate()->get()->keyBy('attribute_definition_id');

        $connection->attributeValues()->delete();

        foreach ($attributeValues as $value) {
            $list = isset($value['identifier_list_value']) ? HighlightWordsResolver::normalize($value['identifier_list_value']) : null;

            // A list saved from a page that may be out of date is merged with what is stored now,
            // so tokens added since that page loaded (the VRChat name, a linked friend) survive.
            if ($list !== null && isset($value['identifier_list_base'])) {
                $current = HighlightWordsResolver::normalize($existing->get($value['attribute_definition_id'])?->identifier_list_value);
                $list = ListMerge::threeWay($current, HighlightWordsResolver::normalize($value['identifier_list_base']), $list);

                // Nothing left on either side: no value to keep.
                if ($list === []) {
                    continue;
                }
            }

            ConnectionAttributeValue::create([
                'connection_id' => $connection->id,
                'attribute_definition_id' => $value['attribute_definition_id'],
                'value_ciphertext' => $value['value_ciphertext'] ?? null,
                'identifier_value' => $value['identifier_value'] ?? null,
                'boolean_value' => $value['boolean_value'] ?? null,
                'identifier_list_value' => $list,
            ]);
        }
    }

    private function findOwned(Request $request, string $id): Connection
    {
        return $request->user()->connections()->where('id', $id)->firstOrFail();
    }
}
