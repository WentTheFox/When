<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\ConnectionAttributeDefinition;
use App\Models\ConnectionAttributeValue;
use App\Models\User;
use App\Support\FieldFeatures;
use App\Support\FieldMigrations;
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
     * Rename and/or update a choice field's options. The type changes only through migrate(),
     * since existing values are stored in the column that type dictates. The options blob is E2EE, so the server cannot verify the
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
     * In-place type change ("Migrate field"). Which changes exist is App\Support\FieldMigrations; this runs one.
     *
     * Values that are E2EE can only be converted by the browser, so it sends the new values together with what each
     * was made from (`base_ciphertext`, or `base_identifier_list` / `base_boolean` for a plaintext source). All
     * values are swapped, the type flipped and the options / icon rules replaced in one all-or-nothing step: if any
     * value changed since the browser read it, or the set of values differs, nothing changes (409) and the page
     * reloads. A converted value that comes out empty removes the value. Plaintext-to-plaintext changes
     * (identifier <-> identifier list) need nothing from the browser and are done here.
     *
     * Features the new type can't serve (FieldFeatures::bindingsBrokenBy) block the change unless the owner agreed to
     * release them (`release_bindings`). The Notes field can't be migrated: the notes feature relies on it being multi-line.
     * Returns how many values were converted and the labels of the released bindings.
     */
    public function migrate(Request $request, string $definition): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'to' => ['required', 'string'],
            'strategy' => ['nullable', 'in:first,join'],
            'values' => ['sometimes', 'array', 'max:5000'],
            'values.*.connection_id' => ['required', 'uuid', 'distinct', Rule::exists('connections', 'id')->where('user_id', $user->id)],
            'values.*.base_ciphertext' => ['nullable', 'string'],
            'values.*.base_identifier_list' => ['nullable', 'array'],
            'values.*.base_identifier_list.*' => ['string'],
            'values.*.base_boolean' => ['nullable', 'boolean'],
            'values.*.value_ciphertext' => ['nullable', 'string'],
            'values.*.identifier_value' => ['nullable', 'string', 'max:1000'],
            'values.*.identifier_list_value' => ['nullable', 'array', 'max:500'],
            'values.*.identifier_list_value.*' => ['nullable', 'string', 'max:1000'],
            // Replaces the field's choices: required when it becomes a choice field.
            'options_ciphertext' => ['nullable', 'string'],
            // The browser re-filters the field's icon rules for the new type; the server can't read them.
            'icon_rules_ciphertext' => ['nullable', 'string'],
            'release_bindings' => ['sometimes', 'boolean'],
        ]);

        $result = DB::transaction(function () use ($user, $definition, $data) {
            // Same row lock as destroy() and the Settings save: no binding or delete can interleave.
            $owner = User::whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $definition = ConnectionAttributeDefinition::where('user_id', $owner->id)->where('id', $definition)->lockForUpdate()->firstOrFail();

            $path = FieldMigrations::find($definition->type, $data['to']);
            if ($path === null) {
                throw ValidationException::withMessages(['definition' => 'This field can\'t be migrated to that type.']);
            }

            if ($owner->notes_field_definition_id === $definition->id) {
                throw ValidationException::withMessages(['definition' => 'The Notes field has to stay multi-line.']);
            }

            if (isset($path['strategies']) && ! in_array($data['strategy'] ?? null, $path['strategies'], true)) {
                throw ValidationException::withMessages(['strategy' => 'Say how to turn several entries into one.']);
            }

            if ($data['to'] === 'radio' && empty($data['options_ciphertext'])) {
                throw ValidationException::withMessages(['options_ciphertext' => 'A choice field needs its choices.']);
            }

            $broken = FieldFeatures::bindingsBrokenBy($owner, $definition->id, $data['to']);
            if ($broken !== [] && ! ($data['release_bindings'] ?? false)) {
                throw ValidationException::withMessages([
                    'definition' => 'This type can\'t be used by: '.implode(', ', $broken).'. Agree to release them, or choose another field in Settings first.',
                ]);
            }

            $rows = ConnectionAttributeValue::where('attribute_definition_id', $definition->id)->lockForUpdate()->get()->keyBy('connection_id');
            /** @var array<string, array<string, mixed>> $sent */
            $sent = array_column($data['values'] ?? [], null, 'connection_id');

            if ($path['tier'] !== FieldMigrations::TIER_PLAIN) {
                $held = $rows->keys()->all();
                $given = array_keys($sent);
                sort($held);
                sort($given);

                if ($held !== $given || $rows->contains(fn (ConnectionAttributeValue $row) => ! $this->isBasedOn($row, $definition->type, $sent[$row->connection_id]))) {
                    abort(409, 'The values changed while migrating. Nothing was migrated; reload and try again.');
                }
            }

            $converted = 0;

            foreach ($rows as $connectionId => $row) {
                $next = $this->migratedColumns($row, $path, $data['to'], $sent[$connectionId] ?? [], $data['strategy'] ?? null);

                if ($next === null) {
                    $row->delete();

                    continue;
                }

                // Exactly the target's column is set (ConnectionController::assertValueShapesMatchDefinitions).
                $row->update($next + ['value_ciphertext' => null, 'identifier_value' => null, 'identifier_list_value' => null, 'boolean_value' => null]);
                $converted++;
            }

            $update = ['type' => $data['to'], 'options_ciphertext' => $data['to'] === 'radio' ? $data['options_ciphertext'] : null];
            if (! empty($data['icon_rules_ciphertext'])) {
                $update['icon_rules_ciphertext'] = $data['icon_rules_ciphertext'];
            }
            $definition->update($update);

            FieldFeatures::release($owner, $definition->id, array_keys($broken));

            return ['converted' => $converted, 'released' => array_values($broken)];
        });

        return response()->json($result);
    }

    /** @param array<string, mixed> $sent */
    private function isBasedOn(ConnectionAttributeValue $row, string $fromType, array $sent): bool
    {
        return match ($fromType) {
            'identifier_list' => array_values((array) $row->identifier_list_value) === array_values((array) ($sent['base_identifier_list'] ?? [])),
            'boolean' => array_key_exists('base_boolean', $sent) && $row->boolean_value === (bool) $sent['base_boolean'],
            default => $row->value_ciphertext !== null && $row->value_ciphertext === ($sent['base_ciphertext'] ?? null),
        };
    }

    /**
     * The column to write for one value, or null when it came out empty (the value is then removed).
     *
     * @param  array{from: string, to: string, tier: string}  $path
     * @param  array<string, mixed>  $sent
     * @return array<string, mixed>|null
     */
    private function migratedColumns(ConnectionAttributeValue $row, array $path, string $to, array $sent, ?string $strategy): ?array
    {
        if ($path['tier'] === FieldMigrations::TIER_PLAIN) {
            if ($to === 'identifier_list') {
                $value = trim((string) $row->identifier_value);

                return $value === '' ? null : ['identifier_list_value' => [$value]];
            }

            $entries = array_values(array_filter(array_map(fn ($e) => trim((string) $e), (array) $row->identifier_list_value), fn ($e) => $e !== ''));
            if ($entries === []) {
                return null;
            }

            // An identifier holds 1000 characters at most; a joined list longer than that is cut.
            return ['identifier_value' => mb_substr($strategy === 'join' ? implode(', ', $entries) : $entries[0], 0, 1000)];
        }

        if ($to === 'identifier') {
            $value = trim((string) ($sent['identifier_value'] ?? ''));

            return $value === '' ? null : ['identifier_value' => $value];
        }

        if ($to === 'identifier_list') {
            $entries = array_values(array_filter(array_map(fn ($e) => trim((string) $e), (array) ($sent['identifier_list_value'] ?? [])), fn ($e) => $e !== ''));

            return $entries === [] ? null : ['identifier_list_value' => $entries];
        }

        $ciphertext = $sent['value_ciphertext'] ?? null;

        return $ciphertext === null || $ciphertext === '' ? null : ['value_ciphertext' => $ciphertext];
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
