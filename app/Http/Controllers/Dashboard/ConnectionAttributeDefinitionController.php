<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Support\CustomFieldPurpose;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * label_ciphertext is always client-vault E2EE (§0.1) — see
 * ConnectionController's doc comment. is_e2ee only governs this
 * definition's *values* (ConnectionController::syncAttributeValues()).
 *
 * is_e2ee/purpose are set once here and never change afterward — there is
 * deliberately no update() route on this controller, so a field's tier
 * can't be flipped after values already exist under it.
 */
class ConnectionAttributeDefinitionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['required', 'uuid', 'unique:connection_attribute_definitions,id'],
            'label_ciphertext' => ['required', 'string'],
            'type' => ['required', 'in:text,textarea,date,number,url,email,phone,radio'],
            // Only meaningful for 'radio' (JSON-encoded {"choices": [...]},
            // client-encrypted with this definition's own record key — same
            // key as label_ciphertext, not any connection's key.
            'options_ciphertext' => ['nullable', 'string'],
            'is_e2ee' => ['sometimes', 'boolean'],
            'purpose' => ['nullable', 'string', Rule::in(CustomFieldPurpose::KEYS)],
        ]);

        $isE2ee = $data['is_e2ee'] ?? true;

        if ($isE2ee && ($data['purpose'] ?? null) !== null) {
            throw ValidationException::withMessages([
                'purpose' => 'A purpose can only be set on a field with client-side encryption disabled.',
            ]);
        }

        $definition = $request->user()->connectionAttributeDefinitions()->create([
            'id' => $data['id'],
            'label_ciphertext' => $data['label_ciphertext'],
            'type' => $data['type'],
            'options_ciphertext' => $data['options_ciphertext'] ?? null,
            'is_e2ee' => $isE2ee,
            'purpose' => $isE2ee ? null : ($data['purpose'] ?? null),
        ]);

        return response()->json(['id' => $definition->id], 201);
    }

    public function destroy(Request $request, string $definition): JsonResponse
    {
        // forceDelete(), not delete() — see ActivityLocalizationController::destroy's
        // comment: SoftDeletes on this model exists only for account-wide
        // deletion, not this single-record user action.
        $request->user()->connectionAttributeDefinitions()->where('id', $definition)->firstOrFail()->forceDelete();

        return response()->json(['status' => 'ok']);
    }
}
