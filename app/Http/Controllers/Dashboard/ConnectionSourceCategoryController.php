<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Support\ColorSwatchKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * name_ciphertext is client-vault E2EE (§0.1) — see ConnectionController's
 * doc comment. color_key is plaintext by design (a palette KEY, never a raw
 * hex — see ColorSwatchKey's own doc comment) and drives the dashboard
 * connections-graph widget's source node coloring; it isn't sensitive
 * connection data, just a display preference.
 */
class ConnectionSourceCategoryController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['required', 'uuid', 'unique:connection_source_categories,id'],
            'name_ciphertext' => ['required', 'string'],
            'color_key' => ['nullable', Rule::enum(ColorSwatchKey::class)],
        ]);

        $category = $request->user()->connectionSourceCategories()->create($data);

        return response()->json(['id' => $category->id], 201);
    }

    public function update(Request $request, string $category): JsonResponse
    {
        $data = $request->validate([
            'name_ciphertext' => ['required', 'string'],
            'color_key' => ['nullable', Rule::enum(ColorSwatchKey::class)],
        ]);

        $request->user()->connectionSourceCategories()->where('id', $category)->firstOrFail()->update($data);

        return response()->json(['status' => 'ok']);
    }

    public function destroy(Request $request, string $category): JsonResponse
    {
        // forceDelete(), not delete() — see ActivityRoleController::destroy's
        // comment: SoftDeletes on this model exists only for account-wide
        // deletion, not this single-record user action.
        $request->user()->connectionSourceCategories()->where('id', $category)->firstOrFail()->forceDelete();

        return response()->json(['status' => 'ok']);
    }
}
