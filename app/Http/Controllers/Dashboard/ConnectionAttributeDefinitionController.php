<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\ConnectionAttributeDefinition;
use App\Models\ConnectionAttributeValue;
use App\Models\User;
use App\Support\FieldFeatures;
use App\Support\SortOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** label_ciphertext is client-vault E2EE (§0.1) — see ConnectionController's doc comment. */
class ConnectionAttributeDefinitionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['required', 'uuid', 'unique:connection_attribute_definitions,id'],
            'label_ciphertext' => ['required', 'string'],
            'type' => ['required', 'in:text,textarea,date,number,url,email,phone,radio,country,list,link_list,identifier,identifier_list,boolean,connection'],
            // Only meaningful for 'radio' (JSON-encoded {"choices": [...]},
            // client-encrypted with this definition's own record key — same
            // key as label_ciphertext, not any connection's key.
            'options_ciphertext' => ['nullable', 'string'],
        ]);

        $definition = $request->user()->connectionAttributeDefinitions()->create($data + ['sort_order' => SortOrder::next($request->user()->connectionAttributeDefinitions())]);

        return response()->json(['id' => $definition->id], 201);
    }

    /**
     * Rename and/or update a choice field's options. The type is fixed for the
     * life of the field, since existing values are stored in the column that
     * type dictates. The options blob is E2EE, so the server cannot verify the
     * change is additive (new choices / reordering only); the client enforces it.
     */
    public function update(Request $request, string $definition): JsonResponse
    {
        $definition = $request->user()->connectionAttributeDefinitions()->where('id', $definition)->firstOrFail();

        $data = $request->validate([
            'label_ciphertext' => ['required_without_all:options_ciphertext,icon_rules_ciphertext', 'string'],
            'options_ciphertext' => ['required_without_all:label_ciphertext,icon_rules_ciphertext', 'string'],
            // Per-field icon rules, any field type. One E2EE blob that carries
            // its own schema version inside the ciphertext, so the server can
            // neither read nor validate it. Clearing the rules means saving an
            // encrypted empty list, not null.
            'icon_rules_ciphertext' => ['required_without_all:label_ciphertext,options_ciphertext', 'string'],
        ]);

        if (isset($data['options_ciphertext']) && $definition->type !== 'radio') {
            throw ValidationException::withMessages(['options_ciphertext' => 'Only a choice field has options.']);
        }

        $definition->update($data);

        return response()->json(['id' => $definition->id]);
    }

    /**
     * Bulk "copy this text field into the identifier field" backend. The
     * client decrypts the source values itself and sends them here in
     * plaintext (identifiers are §0.2-tier, encrypted at rest only). Strictly
     * fill-if-empty, enforced here rather than trusted to the client: rows
     * that already hold a non-empty identifier are skipped, so re-running the
     * copy — or two tabs racing — can never overwrite anything.
     * Returns the connection ids actually written.
     */
    public function fillIdentifiers(Request $request, string $definition): JsonResponse
    {
        $user = $request->user();
        $definition = $user->connectionAttributeDefinitions()->where('id', $definition)->firstOrFail();

        if ($definition->type !== 'identifier') {
            throw ValidationException::withMessages(['definition' => 'Not an identifier field.']);
        }

        $data = $request->validate([
            'values' => ['required', 'array', 'max:500'],
            'values.*.connection_id' => ['required', 'uuid', Rule::exists('connections', 'id')->where('user_id', $user->id)],
            'values.*.identifier_value' => ['required', 'string', 'max:1000'],
        ]);

        $filled = DB::transaction(function () use ($data, $definition) {
            $filled = [];

            foreach ($data['values'] as $item) {
                $row = ConnectionAttributeValue::where('connection_id', $item['connection_id'])
                    ->where('attribute_definition_id', $definition->id)
                    ->lockForUpdate()
                    ->first();

                if ($row === null) {
                    ConnectionAttributeValue::create([
                        'connection_id' => $item['connection_id'],
                        'attribute_definition_id' => $definition->id,
                        'identifier_value' => $item['identifier_value'],
                    ]);
                } elseif ($row->identifier_value === null || $row->identifier_value === '') {
                    $row->update(['identifier_value' => $item['identifier_value']]);
                } else {
                    continue;
                }

                $filled[] = $item['connection_id'];
            }

            return $filled;
        });

        return response()->json(['filled' => $filled]);
    }

    /**
     * One-way migration: an 'identifier' field becomes an 'identifier_list'
     * field in place. Each existing value turns into a one-entry list (empty
     * values are dropped). The field keeps its id, label, position and every
     * feature binding. There is deliberately no way back: a list can hold
     * several entries, a single identifier can't.
     * Returns how many values were converted.
     */
    public function convertToList(Request $request, string $definition): JsonResponse
    {
        $userId = $request->user()->getKey();

        $converted = DB::transaction(function () use ($userId, $definition) {
            // Same row lock as destroy() and the Settings save: no binding or delete can interleave.
            User::whereKey($userId)->lockForUpdate()->firstOrFail();
            $definition = ConnectionAttributeDefinition::where('user_id', $userId)->where('id', $definition)->lockForUpdate()->firstOrFail();

            if ($definition->type !== 'identifier') {
                throw ValidationException::withMessages(['definition' => 'Only an identifier field can be converted to a list.']);
            }

            $converted = 0;

            ConnectionAttributeValue::where('attribute_definition_id', $definition->id)->lockForUpdate()->get()
                ->each(function (ConnectionAttributeValue $row) use (&$converted) {
                    $value = trim((string) $row->identifier_value);

                    if ($value === '') {
                        $row->delete();

                        return;
                    }

                    $row->update(['identifier_value' => null, 'identifier_list_value' => [$value]]);
                    $converted++;
                });

            $definition->update(['type' => 'identifier_list']);

            return $converted;
        });

        return response()->json(['converted' => $converted]);
    }

    /**
     * One-way migration: a 'textarea' field becomes an E2EE 'list' field in place.
     *
     * The values are E2EE, so only the browser can do the work: it decrypts each one, normalizes it to
     * one entry per line, pads it (see encryptedList.ts) and re-encrypts it. This endpoint takes those
     * new ciphertexts together with the ciphertext each was made from (`base_ciphertext`), swaps them in
     * and flips the type in one all-or-nothing step. If any value changed since the browser read it, or
     * the set of values differs, nothing is changed (409) and the page reloads its data.
     * `value_ciphertext` null means the list came out empty: that value is removed.
     * The Notes field cannot be converted, since the notes feature relies on it being multi-line.
     * Returns how many values were converted.
     */
    public function convertToEncryptedList(Request $request, string $definition): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'values' => ['present', 'array', 'max:5000'],
            'values.*.connection_id' => ['required', 'uuid', 'distinct', Rule::exists('connections', 'id')->where('user_id', $user->id)],
            'values.*.base_ciphertext' => ['required', 'string'],
            'values.*.value_ciphertext' => ['nullable', 'string'],
        ]);

        $converted = DB::transaction(function () use ($user, $definition, $data) {
            // Same row lock as destroy() and the Settings save: no binding or delete can interleave.
            $owner = User::whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $definition = ConnectionAttributeDefinition::where('user_id', $owner->id)->where('id', $definition)->lockForUpdate()->firstOrFail();

            if ($definition->type !== 'textarea') {
                throw ValidationException::withMessages(['definition' => 'Only a multi-line field can be converted to a list.']);
            }

            if ($owner->notes_field_definition_id === $definition->id) {
                throw ValidationException::withMessages(['definition' => 'The Notes field has to stay multi-line.']);
            }

            $rows = ConnectionAttributeValue::where('attribute_definition_id', $definition->id)->lockForUpdate()->get()->keyBy('connection_id');
            /** @var array<string, array{connection_id: string, base_ciphertext: string, value_ciphertext?: string|null}> $sent */
            $sent = array_column($data['values'], null, 'connection_id');

            $held = $rows->keys()->all();
            $given = array_keys($sent);
            sort($held);
            sort($given);

            $stale = $held !== $given
                || $rows->contains(fn (ConnectionAttributeValue $row) => $row->value_ciphertext !== $sent[$row->connection_id]['base_ciphertext']);

            if ($stale) {
                abort(409, 'The values changed while converting. Nothing was converted; reload and try again.');
            }

            $converted = 0;

            foreach ($rows as $connectionId => $row) {
                $next = $sent[$connectionId]['value_ciphertext'] ?? null;

                if ($next === null || $next === '') {
                    $row->delete();

                    continue;
                }

                $row->update(['value_ciphertext' => $next]);
                $converted++;
            }

            $definition->update(['type' => 'list']);

            return $converted;
        });

        return response()->json(['converted' => $converted]);
    }

    /**
     * Orphan cleanup for 'connection'-type definitions. The referenced id is
     * E2EE so the server can't tell which values dangle; the client decrypts,
     * finds the ones pointing at deleted connections, and asks for those
     * specific (connection, definition) rows to be removed. Idempotent —
     * rows already gone are simply not counted.
     */
    public function clearValues(Request $request, string $definition): JsonResponse
    {
        $user = $request->user();
        $definition = $user->connectionAttributeDefinitions()->where('id', $definition)->firstOrFail();

        if ($definition->type !== 'connection') {
            throw ValidationException::withMessages(['definition' => 'Not a connection field.']);
        }

        $data = $request->validate([
            'connection_ids' => ['required', 'array', 'max:500'],
            'connection_ids.*' => ['uuid', Rule::exists('connections', 'id')->where('user_id', $user->id)],
        ]);

        $cleared = ConnectionAttributeValue::where('attribute_definition_id', $definition->id)
            ->whereIn('connection_id', $data['connection_ids'])
            ->delete();

        return response()->json(['cleared' => $cleared]);
    }

    /**
     * Refuses while a site feature depends on the field (see FieldFeatures),
     * so the feature can never silently lose its source. The check and the
     * delete share a transaction that locks the user's row, which is also what
     * saving the Settings page writes to, so a field can't be bound to a
     * feature and deleted at the same moment.
     */
    public function destroy(Request $request, string $definition): JsonResponse
    {
        $userId = $request->user()->getKey();

        DB::transaction(function () use ($userId, $definition) {
            $user = User::whereKey($userId)->lockForUpdate()->firstOrFail();
            $definition = $user->connectionAttributeDefinitions()->where('id', $definition)->firstOrFail();

            $features = FieldFeatures::labelsFor($user, $definition->id);
            if ($features !== []) {
                throw ValidationException::withMessages([
                    'definition' => 'This field is used by: '.implode(', ', $features).'. '
                        .'Choose a different field (or none) in Settings first, then delete it.',
                ]);
            }

            // forceDelete(), not delete() — see ActivityLocalizationController::destroy's
            // comment: SoftDeletes on this model exists only for account-wide
            // deletion, not this single-record user action.
            $definition->forceDelete();
        });

        return response()->json(['status' => 'ok']);
    }
}
