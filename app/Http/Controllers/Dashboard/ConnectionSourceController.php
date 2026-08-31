<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** name_ciphertext is client-vault E2EE (§0.1) — see ConnectionController's doc comment. */
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
        ]);

        $source = $request->user()->connectionSources()->create($data);

        return response()->json(['id' => $source->id], 201);
    }

    public function update(Request $request, string $source): JsonResponse
    {
        $data = $request->validate([
            'category_id' => ['nullable', 'uuid', Rule::exists('connection_source_categories', 'id')->where('user_id', $request->user()->id)],
            'name_ciphertext' => ['required', 'string'],
        ]);

        $request->user()->connectionSources()->where('id', $source)->firstOrFail()->update($data);

        return response()->json(['status' => 'ok']);
    }

    public function destroy(Request $request, string $source): JsonResponse
    {
        $request->user()->connectionSources()->where('id', $source)->firstOrFail()->delete();

        return response()->json(['status' => 'ok']);
    }
}
