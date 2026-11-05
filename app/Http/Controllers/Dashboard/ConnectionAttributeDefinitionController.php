<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
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
            'type' => ['required', 'in:text,textarea,date,number,url,email,phone,radio,identifier,identifier_list,boolean,connection'],
            // Only meaningful for 'radio' (JSON-encoded {"choices": [...]},
            // client-encrypted with this definition's own record key — same
            // key as label_ciphertext, not any connection's key.
            'options_ciphertext' => ['nullable', 'string'],
        ]);

        $definition = $request->user()->connectionAttributeDefinitions()->create($data + ['sort_order' => SortOrder::next($request->user()->connectionAttributeDefinitions())]);

        return response()->json(['id' => $definition->id], 201);
    }

    /**
     * Rename: only the (client-encrypted) label can change. The type is fixed
     * for the life of the field, since existing values are stored in the
     * column that type dictates.
     */
    public function update(Request $request, string $definition): JsonResponse
    {
        $definition = $request->user()->connectionAttributeDefinitions()->where('id', $definition)->firstOrFail();

        $data = $request->validate([
            'label_ciphertext' => ['required', 'string'],
        ]);

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
