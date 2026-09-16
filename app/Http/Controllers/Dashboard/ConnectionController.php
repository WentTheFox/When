<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Models\ConnectionAttributeDefinition;
use App\Models\ConnectionAttributeValue;
use App\Support\CustomFieldPurpose;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
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
 * One exception: an attribute value whose definition has is_e2ee false is
 * §0.2 tier, not §0.1 — the client sends/receives it as plain `value`
 * (never `value_ciphertext`), and this controller is the one that calls
 * Crypt::encryptString/decryptString on it, same pattern as
 * SettingsController::updateCalendarUrl(). See syncAttributeValues() and
 * serialize() below.
 */
class ConnectionController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Dashboard/Connections', [
            'connections' => $user->connections()->with(['attributeValues', 'sources'])->get([
                'id', 'name_ciphertext', 'notes_ciphertext', 'introduced_by_ciphertext', 'share_link_id', 'archived',
            ])->map(fn (Connection $c) => $this->serialize($c)),
            'sources' => $user->connectionSources()->get(['id', 'category_id', 'name_ciphertext']),
            'categories' => $user->connectionSourceCategories()->get(['id', 'name_ciphertext', 'color_key']),
            'attributeDefinitions' => $user->connectionAttributeDefinitions()->get(['id', 'label_ciphertext', 'type', 'options_ciphertext', 'is_e2ee', 'purpose']),
            'edges' => $user->connectionEdges()->get(['id', 'from_connection_id', 'to_connection_id', 'label_ciphertext']),
        ]);
    }

    /**
     * Feeds the dashboard-wide quick-search box (QuickSearch.vue) — just
     * id + name_ciphertext, nothing else a card carries, since search only
     * ever needs a name to match against and something to link to. The
     * client decrypts every name locally after the vault unlocks; this
     * never sees or handles plaintext.
     */
    public function searchIndex(Request $request): JsonResponse
    {
        return response()->json(
            $request->user()->connections()->get(['id', 'name_ciphertext'])
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateConnection($request, requireId: true);

        $connection = DB::transaction(function () use ($request, $data) {
            $connection = $request->user()->connections()->create([
                'id' => $data['id'],
                'name_ciphertext' => $data['name_ciphertext'],
                'notes_ciphertext' => $data['notes_ciphertext'] ?? null,
                'introduced_by_ciphertext' => $data['introduced_by_ciphertext'] ?? null,
                'share_link_id' => $data['share_link_id'] ?? null,
                'archived' => $data['archived'] ?? false,
            ]);

            if (array_key_exists('source_ids', $data)) {
                $connection->sources()->sync($data['source_ids']);
            }

            $this->syncAttributeValues($connection, $data['attribute_values'] ?? null);

            return $connection;
        });

        return response()->json($this->serialize($connection->refresh()), 201);
    }

    public function update(Request $request, string $connection): JsonResponse
    {
        $connection = $this->findOwned($request, $connection);
        $data = $this->validateConnection($request, requireId: false);

        DB::transaction(function () use ($connection, $data) {
            $connection->fill(array_filter([
                'name_ciphertext' => $data['name_ciphertext'] ?? null,
                'notes_ciphertext' => $data['notes_ciphertext'] ?? null,
                'introduced_by_ciphertext' => $data['introduced_by_ciphertext'] ?? null,
                'archived' => $data['archived'] ?? null,
            ], fn ($value) => $value !== null))->save();

            // Not folded into the array_filter above — share_link_id is a
            // real "untie this connection" action (the ShareLinkCard.vue
            // picker's "None" option sends it explicitly as null), and
            // array_filter's null check would silently drop that update,
            // leaving the old link attached with no error and no visible
            // sign the request did anything at all.
            if (array_key_exists('share_link_id', $data)) {
                $connection->share_link_id = $data['share_link_id'];
                $connection->save();
            }

            if (array_key_exists('source_ids', $data)) {
                $connection->sources()->sync($data['source_ids']);
            }

            if (array_key_exists('attribute_values', $data)) {
                $this->syncAttributeValues($connection, $data['attribute_values']);
            }
        });

        return response()->json($this->serialize($connection->refresh()));
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
            'introduced_by_ciphertext' => $connection->introduced_by_ciphertext,
            'share_link_id' => $connection->share_link_id,
            'archived' => $connection->archived,
            'attribute_values' => $connection->attributeValues()
                ->get(['attribute_definition_id', 'value_ciphertext', 'value_appkey_ciphertext'])
                ->map(fn (ConnectionAttributeValue $value) => [
                    'attribute_definition_id' => $value->attribute_definition_id,
                    'value_ciphertext' => $value->value_ciphertext,
                    // §0.2 tier — decrypted here for the owner's own view,
                    // same as calendar_url_ciphertext elsewhere; never E2EE.
                    'value' => $value->value_appkey_ciphertext === null
                        ? null
                        : Crypt::decryptString($value->value_appkey_ciphertext),
                ]),
        ];
    }

    public function destroy(Request $request, string $connection): JsonResponse
    {
        // forceDelete(), not delete() — see ActivityLocalizationController::destroy's
        // comment: SoftDeletes on this model exists only for account-wide
        // deletion, not this single-record user action. Also matters here
        // specifically: connection_attribute_values/connection_source_links'
        // cascadeOnDelete() FKs only fire on a real DELETE.
        $this->findOwned($request, $connection)->forceDelete();

        return response()->json(['status' => 'ok']);
    }

    /**
     * source_ids/share_link_id/attribute_definition_id all scope their
     * `exists` check to the requesting user's own rows — an unscoped
     * `exists:table,id` only proves the id exists *somewhere*, not that it
     * belongs to this owner, which would otherwise let one user attach
     * their own connection to another user's source, share link, or
     * attribute definition (a real cross-user IDOR, not just a missing
     * test — see ConnectionsIsolationTest).
     */
    private function validateConnection(Request $request, bool $requireId): array
    {
        $userId = $request->user()->id;

        return $request->validate([
            'id' => $requireId ? ['required', 'uuid', 'unique:connections,id'] : ['sometimes'],
            'source_ids' => ['nullable', 'array'],
            'source_ids.*' => ['uuid', Rule::exists('connection_sources', 'id')->where('user_id', $userId)],
            'name_ciphertext' => $requireId ? ['required', 'string'] : ['sometimes', 'string'],
            'notes_ciphertext' => ['nullable', 'string'],
            'introduced_by_ciphertext' => ['nullable', 'string'],
            'share_link_id' => ['nullable', 'uuid', Rule::exists('share_links', 'id')->where('user_id', $userId)],
            'archived' => ['nullable', 'boolean'],
            'attribute_values' => ['nullable', 'array'],
            'attribute_values.*.attribute_definition_id' => ['required', 'uuid', Rule::exists('connection_attribute_definitions', 'id')->where('user_id', $userId)],
            // Exactly one of these two is required per row, decided by the
            // referenced definition's is_e2ee — enforced in
            // e2eeAttributeRow()/serversideAttributeRow() below, since a
            // static rule here can't see the definition it belongs to.
            'attribute_values.*.value_ciphertext' => ['nullable', 'string'],
            'attribute_values.*.value' => ['nullable', 'string'],
        ]);
    }

    private function syncAttributeValues(Connection $connection, ?array $attributeValues): void
    {
        if ($attributeValues === null) {
            return;
        }

        $definitions = ConnectionAttributeDefinition::whereIn(
            'id',
            collect($attributeValues)->pluck('attribute_definition_id')
        )->get(['id', 'is_e2ee', 'purpose'])->keyBy('id');

        $rows = [];
        foreach ($attributeValues as $value) {
            // Already guarded by validateConnection()'s ownership-scoped
            // Rule::exists — a missing definition here would mean the id
            // vanished between validation and this query, defensive only.
            $definition = $definitions->get($value['attribute_definition_id']);
            if ($definition === null) {
                continue;
            }

            $rows[] = $definition->is_e2ee
                ? $this->e2eeAttributeRow($connection, $definition, $value)
                : $this->serversideAttributeRow($connection, $definition, $value);
        }

        $connection->attributeValues()->delete();

        foreach ($rows as $row) {
            ConnectionAttributeValue::create($row);
        }
    }

    /** @param  array<string, mixed>  $value */
    private function e2eeAttributeRow(Connection $connection, ConnectionAttributeDefinition $definition, array $value): array
    {
        Validator::make($value, ['value_ciphertext' => ['required', 'string']])->validate();

        return [
            'connection_id' => $connection->id,
            'attribute_definition_id' => $definition->id,
            'value_ciphertext' => $value['value_ciphertext'],
            'value_appkey_ciphertext' => null,
        ];
    }

    /**
     * §0.2 tier — the value travels as plaintext in this request (never
     * client-encrypted) and is encrypted here with Crypt::encryptString
     * (APP_KEY), same pattern as SettingsController::updateCalendarUrl().
     * purpose (App\Support\CustomFieldPurpose) adds format rules on top of
     * the base ones, e.g. a "discord" field must look like a Discord
     * username.
     *
     * @param  array<string, mixed>  $value
     */
    private function serversideAttributeRow(Connection $connection, ConnectionAttributeDefinition $definition, array $value): array
    {
        Validator::make($value, [
            'value' => array_merge(['required', 'string', 'max:255'], CustomFieldPurpose::valueRules($definition->purpose)),
        ])->validate();

        return [
            'connection_id' => $connection->id,
            'attribute_definition_id' => $definition->id,
            'value_ciphertext' => null,
            'value_appkey_ciphertext' => Crypt::encryptString($value['value']),
        ];
    }

    private function findOwned(Request $request, string $id): Connection
    {
        return $request->user()->connections()->where('id', $id)->firstOrFail();
    }
}
