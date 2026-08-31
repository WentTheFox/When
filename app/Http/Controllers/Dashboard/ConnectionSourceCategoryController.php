<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** name_ciphertext is client-vault E2EE (§0.1) — see ConnectionController's doc comment. */
class ConnectionSourceCategoryController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['required', 'uuid', 'unique:connection_source_categories,id'],
            'name_ciphertext' => ['required', 'string'],
        ]);

        $category = $request->user()->connectionSourceCategories()->create($data);

        return response()->json(['id' => $category->id], 201);
    }

    public function update(Request $request, string $category): JsonResponse
    {
        $data = $request->validate(['name_ciphertext' => ['required', 'string']]);

        $request->user()->connectionSourceCategories()->where('id', $category)->firstOrFail()->update($data);

        return response()->json(['status' => 'ok']);
    }

    public function destroy(Request $request, string $category): JsonResponse
    {
        $request->user()->connectionSourceCategories()->where('id', $category)->firstOrFail()->delete();

        return response()->json(['status' => 'ok']);
    }
}
