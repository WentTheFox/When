<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** name_ciphertext is client-vault E2EE (§0.1) — see ConnectionController's doc comment. */
class ConnectionSourceController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['required', 'uuid', 'unique:connection_sources,id'],
            'category_id' => ['nullable', 'uuid', 'exists:connection_source_categories,id'],
            'name_ciphertext' => ['required', 'string'],
        ]);

        $source = $request->user()->connectionSources()->create($data);

        return response()->json(['id' => $source->id], 201);
    }

    public function update(Request $request, string $source): JsonResponse
    {
        $data = $request->validate([
            'category_id' => ['nullable', 'uuid', 'exists:connection_source_categories,id'],
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
