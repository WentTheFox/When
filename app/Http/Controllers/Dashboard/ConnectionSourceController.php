<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Support\ColorSwatchKey;
use App\Support\SortOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * name_ciphertext and icon_ciphertext are client-vault E2EE (§0.1) — see ConnectionController's doc comment.
 * color_key is a plaintext palette key, like the category's; both icon and color are overrides of the category's.
 */
class ConnectionSourceController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        // category_id's exists check is scoped to this user's own
        // categories — a bare exists:connection_source_categories,id only
        // proves the id exists *somewhere*, not that it's this owner's,
        // which would otherwise let one user attach their own source to
        // another user's category (see ConnectionsIsolationTest).
        $data = $request->validate([
            'id' => ['required', 'uuid', 'unique:connection_sources,id'],
            'category_id' => ['nullable', 'uuid', Rule::exists('connection_source_categories', 'id')->where('user_id', $request->user()->id)],
            'name_ciphertext' => ['required', 'string'],
            'icon_ciphertext' => ['nullable', 'string'],
            // Null: use the category's color.
            'color_key' => ['nullable', Rule::enum(ColorSwatchKey::class)],
        ]);

        $source = $request->user()->connectionSources()->create($data + ['sort_order' => SortOrder::next($request->user()->connectionSources())]);

        return response()->json(['id' => $source->id], 201);
    }

    public function update(Request $request, string $source): JsonResponse
    {
        $data = $request->validate([
            'category_id' => ['nullable', 'uuid', Rule::exists('connection_source_categories', 'id')->where('user_id', $request->user()->id)],
            'name_ciphertext' => ['required', 'string'],
            'icon_ciphertext' => ['nullable', 'string'],
            // Null: use the category's color.
            'color_key' => ['nullable', Rule::enum(ColorSwatchKey::class)],
        ]);

        $request->user()->connectionSources()->where('id', $source)->firstOrFail()->update($data);

        return response()->json(['status' => 'ok']);
    }

    public function destroy(Request $request, string $source): JsonResponse
    {
        // forceDelete(), not delete() — see ActivityLocalizationController::destroy's
        // comment: SoftDeletes on this model exists only for account-wide
        // deletion, not this single-record user action.
        $request->user()->connectionSources()->where('id', $source)->firstOrFail()->forceDelete();

        return response()->json(['status' => 'ok']);
    }
}
