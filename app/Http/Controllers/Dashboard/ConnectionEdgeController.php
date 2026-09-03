<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** label_ciphertext is client-vault E2EE (§0.1) — see ConnectionController's doc comment. */
class ConnectionEdgeController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['required', 'uuid', 'unique:connection_edges,id'],
            'from_connection_id' => ['required', 'uuid', 'exists:connections,id'],
            'to_connection_id' => ['required', 'uuid', 'exists:connections,id', 'different:from_connection_id'],
            'label_ciphertext' => ['nullable', 'string'],
        ]);

        $this->assertOwned($request, $data['from_connection_id']);
        $this->assertOwned($request, $data['to_connection_id']);

        $edge = $request->user()->connectionEdges()->create($data);

        return response()->json(['id' => $edge->id], 201);
    }

    public function destroy(Request $request, string $edge): JsonResponse
    {
        // forceDelete(), not delete() — see ActivityLocalizationController::destroy's
        // comment: SoftDeletes on this model exists only for account-wide
        // deletion, not this single-record user action.
        $request->user()->connectionEdges()->where('id', $edge)->firstOrFail()->forceDelete();

        return response()->json(['status' => 'ok']);
    }

    private function assertOwned(Request $request, string $connectionId): void
    {
        $request->user()->connections()->where('id', $connectionId)->firstOrFail();
    }
}
